<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Result\Project\PublishProjectSourceResult;
use Symfony\Component\Process\Process;

/** Publishes the committed module subtree through an isolated staging repository. */
final class PublishProjectSourceAction implements ActionInterface
{
    public function __invoke(
        ModuleContext $module,
        string $machineName,
        ProjectReleaseBranch $branch,
        ProjectReleaseVersion $version,
    ): PublishProjectSourceResult {
        $sourceRoot = $this->git($module->location->resolvedPath, ['rev-parse', '--show-toplevel']);
        $relativePath = trim($this->git($module->location->resolvedPath, ['rev-parse', '--show-prefix']));
        $sourceCommit = $this->git($sourceRoot, ['rev-parse', 'HEAD']);
        $this->assertCleanModule($sourceRoot, $relativePath);
        $this->assertSafeFiles($sourceRoot, $relativePath);
        $this->assertComposer($module, $machineName);

        $staging = $this->createStagingDirectory();
        try {
            $remote = 'git@git.drupal.org:project/' . $machineName . '.git';
            $this->run($staging, ['init']);
            $this->run($staging, ['remote', 'add', 'drupal', $remote]);
            $branchExists = $this->run($staging, ['ls-remote', '--exit-code', '--heads', 'drupal', $branch->name], false);
            if ($branchExists->isSuccessful()) {
                $this->mustRun($staging, ['fetch', '--no-tags', 'drupal', 'refs/heads/' . $branch->name]);
                $this->mustRun($staging, ['checkout', '-B', $branch->name, 'FETCH_HEAD']);
                $this->mustRun($staging, ['rm', '-r', '--ignore-unmatch', '.']);
            }
            $archive = $staging . DIRECTORY_SEPARATOR . 'module.tar';
            $archiveArgs = ['archive', '--format=tar', '--output=' . $archive, $relativePath === ''
                ? 'HEAD'
                : 'HEAD:' . rtrim($relativePath, '/')];
            $this->mustRun($sourceRoot, $archiveArgs);
            (new \PharData($archive))->extractTo($staging, null, true);
            unlink($archive);
            $this->mustRun($staging, ['add', '-A']);
            $diff = $this->run($staging, ['diff', '--cached', '--quiet'], false);
            if ($diff->getExitCode() === 0 && $branchExists->isSuccessful()) {
                $sha = $this->git($staging, ['rev-parse', 'HEAD']);
                return new PublishProjectSourceResult($branch->name, $sourceCommit, $sha, 0, false, true);
            }
            if ($diff->getExitCode() !== 1) {
                throw new \RuntimeException('Could not inspect staged Drupal.org source changes.');
            }
            $filesChanged = count(array_filter(explode("\n", trim($this->git($staging, ['diff', '--cached', '--name-only'])))));
            $this->mustRun($staging, ['-c', 'core.hooksPath=/dev/null', 'commit', '-m', $branchExists->isSuccessful()
                ? 'Prepare ' . $version->value . ' release'
                : 'Initial release preparation for ' . $version->value]);
            if (!$branchExists->isSuccessful()) {
                $this->mustRun($staging, ['branch', '-M', $branch->name]);
            }
            $this->mustRun($staging, ['push', 'drupal', 'HEAD:refs/heads/' . $branch->name], 'Could not publish to Drupal.org. Verify SSH access with: ssh -T git@git.drupal.org');
            $sha = $this->git($staging, ['rev-parse', 'HEAD']);
            return new PublishProjectSourceResult($branch->name, $sourceCommit, $sha, $filesChanged, !$branchExists->isSuccessful(), false);
        } finally {
            $this->removeDirectory($staging);
        }
    }

    private function assertCleanModule(string $root, string $relativePath): void
    {
        $output = $this->git($root, ['status', '--porcelain', '--', $relativePath === '' ? '.' : $relativePath]);
        if ($output !== '') {
            throw new \RuntimeException("Module source contains uncommitted changes.\n\nCommit or discard them before publishing.\n" . $output);
        }
    }

    private function assertSafeFiles(string $root, string $relativePath): void
    {
        $output = $this->git($root, ['ls-files', '-z', '--', $relativePath === '' ? '.' : $relativePath]);
        foreach (array_filter(explode("\0", $output)) as $file) {
            $name = basename($file);
            if (preg_match('/^(?:\.env(?:\..*)?|auth\.json|id_rsa|id_ed25519|credentials.*|secrets.*|.*\.(?:pem|key))$/i', $name) === 1) {
                throw new \RuntimeException("Potentially sensitive tracked file:\n  " . $file . "\n\nRemove it from the module repository before publishing.");
            }
        }
    }

    private function assertComposer(ModuleContext $module, string $machineName): void
    {
        $path = $module->location->resolvedPath . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($path)) {
            return;
        }
        try {
            $composer = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Module composer.json is not valid JSON.', 0, $exception);
        }
        if (($composer['name'] ?? null) !== 'drupal/' . $machineName || ($composer['type'] ?? null) !== 'drupal-module') {
            throw new \RuntimeException(sprintf('Module composer.json must declare name "drupal/%s" and type "drupal-module" before publishing.', $machineName));
        }
    }

    private function createStagingDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drupalorg-publish-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create the temporary Drupal.org staging repository.');
        }
        return $directory;
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): string
    {
        $process = $this->mustRun($cwd, $args);
        return trim($process->getOutput());
    }

    /** @param list<string> $args */
    private function run(string $cwd, array $args, bool $throw = true): Process
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(60);
        $process->run();
        if ($throw && !$process->isSuccessful()) {
            throw new \RuntimeException('Git operation failed.');
        }
        return $process;
    }

    /** @param list<string> $args */
    private function mustRun(string $cwd, array $args, ?string $message = null): Process
    {
        try {
            return $this->run($cwd, $args);
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException($message ?? $exception->getMessage(), 0, $exception);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || !str_starts_with(basename($directory), 'drupalorg-publish-')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
