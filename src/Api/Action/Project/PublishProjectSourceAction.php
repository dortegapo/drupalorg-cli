<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Project\ProjectRepositoryUrls;
use mglaman\DrupalOrg\Result\Project\PublishProjectSourceResult;
use Symfony\Component\Process\Process;

/** Publishes only the committed module subtree through an isolated repository. */
class PublishProjectSourceAction implements ActionInterface
{
    public function __construct(private readonly ?ProjectRepositoryUrls $urls = null)
    {
    }

    public function __invoke(
        ModuleContext $module,
        string $machineName,
        ProjectReleaseBranch $branch,
        ProjectReleaseVersion $version,
    ): PublishProjectSourceResult {
        $source = $this->sourceContext($module);
        $this->assertCleanModule($source['root'], $source['prefix']);
        $this->assertSafeFiles($source['root'], $source['prefix']);
        $this->assertComposer($module, $machineName);
        [$name, $email] = $this->identity($source['root']);
        $staging = $this->temporaryDirectory('drupalorg-publish-');
        try {
            $remote = ($this->urls ?? ProjectRepositoryUrls::forDrupalOrg($machineName))->writeUrl;
            $this->run($staging, ['init']);
            $this->run($staging, ['remote', 'add', 'drupal', $remote]);
            $branchExists = $this->run($staging, ['ls-remote', '--exit-code', '--heads', 'drupal', $branch->name], false)->isSuccessful();
            if ($branchExists) {
                $this->mustRun($staging, ['fetch', '--no-tags', 'drupal', 'refs/heads/' . $branch->name]);
                $this->mustRun($staging, ['checkout', '-B', $branch->name, 'FETCH_HEAD']);
                $this->mustRun($staging, ['rm', '-r', '--ignore-unmatch', '.']);
            }
            $this->archive($source['root'], $source['prefix'], $staging);
            $this->mustRun($staging, ['add', '-A']);
            $diff = $this->run($staging, ['diff', '--cached', '--quiet'], false);
            if ($diff->getExitCode() === 0 && $branchExists) {
                return new PublishProjectSourceResult($branch->name, $source['commit'], $this->git($staging, ['rev-parse', 'HEAD']), 0, false, true);
            }
            if ($diff->getExitCode() !== 1) {
                throw new \RuntimeException('Could not inspect staged Drupal.org source changes.');
            }
            $filesChanged = count(array_filter(explode("\n", trim($this->git($staging, ['diff', '--cached', '--name-only'])))));
            $this->mustRun($staging, [
                '-c', 'core.hooksPath=/dev/null', '-c', 'user.name=' . $name, '-c', 'user.email=' . $email,
                'commit', '-m', $branchExists ? 'Prepare ' . $version->value . ' release' : 'Initial release preparation for ' . $version->value,
            ]);
            if (!$branchExists) {
                $this->mustRun($staging, ['branch', '-M', $branch->name]);
            }
            $push = $this->run($staging, ['push', 'drupal', 'HEAD:refs/heads/' . $branch->name], false);
            if (!$push->isSuccessful()) {
                throw new \RuntimeException('Drupal.org branch changed while publishing. Fetch the current repository state and rerun.');
            }
            return new PublishProjectSourceResult($branch->name, $source['commit'], $this->git($staging, ['rev-parse', 'HEAD']), $filesChanged, !$branchExists, false);
        } finally {
            $this->removeDirectory($staging, 'drupalorg-publish-');
        }
    }

    /** @return array{root: string, prefix: string, commit: string} */
    private function sourceContext(ModuleContext $module): array
    {
        $root = $this->git($module->location->resolvedPath, ['rev-parse', '--show-toplevel']);
        return [
            'root' => $root,
            'prefix' => trim($this->git($module->location->resolvedPath, ['rev-parse', '--show-prefix'])),
            'commit' => $this->git($root, ['rev-parse', 'HEAD']),
        ];
    }

    private function assertCleanModule(string $root, string $prefix): void
    {
        $path = $prefix === '' ? '.' : $prefix;
        $status = $this->git($root, ['status', '--porcelain', '--', $path]);
        if ($status !== '') {
            throw new \RuntimeException("Module source contains uncommitted changes.\n\nCommit or discard them before publishing.\n" . $status);
        }
    }

    private function assertSafeFiles(string $root, string $prefix): void
    {
        $path = $prefix === '' ? '.' : $prefix;
        $files = array_filter(explode("\0", $this->git($root, ['ls-files', '-z', '--', $path])));
        foreach ($files as $file) {
            $name = basename($file);
            if (preg_match('/^(?:\.env(?:\..*)?|auth\.json|id_rsa|id_ed25519|credentials.*|secrets.*|.*\.(?:pem|key))$/i', $name) === 1) {
                throw new \RuntimeException("Potentially sensitive tracked file:\n  " . $file . "\n\nRemove it from the module repository before publishing.");
            }
            $contents = @file_get_contents($root . DIRECTORY_SEPARATOR . $file);
            if ($contents !== false && preg_match('/-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----/', $contents) === 1) {
                throw new \RuntimeException("Potential private key content in tracked file:\n  " . $file . "\n\nRemove it from the module repository before publishing.");
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

    /** @return array{string, string} */
    private function identity(string $root): array
    {
        $name = trim($this->git($root, ['config', '--get', 'user.name']));
        $email = trim($this->git($root, ['config', '--get', 'user.email']));
        if ($name === '' || $email === '') {
            throw new \RuntimeException('Git identity is not configured. Set user.name and user.email in the source repository before publishing.');
        }
        return [$name, $email];
    }

    private function archive(string $root, string $prefix, string $staging): void
    {
        $archive = $staging . DIRECTORY_SEPARATOR . 'module.tar';
        $tree = $prefix === '' ? 'HEAD' : 'HEAD:' . rtrim($prefix, '/');
        $this->mustRun($root, ['archive', '--format=tar', '--output=' . $archive, $tree]);
        (new \PharData($archive))->extractTo($staging, null, true);
        unlink($archive);
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): string
    {
        return trim($this->mustRun($cwd, $args)->getOutput());
    }

    /** @param list<string> $args */
    private function run(string $cwd, array $args, bool $throw = true): Process
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(60);
        $process->setEnv(array_merge($_ENV, $_SERVER, ['GIT_TERMINAL_PROMPT' => '0']));
        $process->run();
        if ($throw && !$process->isSuccessful()) {
            throw new \RuntimeException('Git operation failed: ' . trim($process->getErrorOutput()));
        }
        return $process;
    }

    /** @param list<string> $args */
    private function mustRun(string $cwd, array $args): Process
    {
        return $this->run($cwd, $args);
    }

    private function temporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create temporary Drupal.org staging repository.');
        }
        return $directory;
    }

    private function removeDirectory(string $directory, string $prefix): void
    {
        if (!is_dir($directory) || !str_starts_with(basename($directory), $prefix)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
