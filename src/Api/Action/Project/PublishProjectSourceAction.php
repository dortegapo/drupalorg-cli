<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Project\ProjectRepositoryUrls;
use mglaman\DrupalOrg\Result\Project\PublishProjectSourceResult;
use mglaman\DrupalOrg\Result\Project\ProjectLocalSourceResult;
use mglaman\DrupalOrg\Result\Project\ProjectSourceStatusResult;
use Symfony\Component\Process\Process;

/** Publishes only the committed module subtree through an isolated repository. */
class PublishProjectSourceAction implements ActionInterface
{
    public function __construct(
        private readonly ?ProjectRepositoryUrls $urls = null,
        private readonly ?InspectLocalProjectSourceAction $localSourceInspector = null,
    ) {
    }

    public function __invoke(
        ModuleContext $module,
        string $machineName,
        ProjectReleaseBranch $branch,
        ProjectReleaseVersion $version,
        ?ProjectSourceStatusResult $expectedSource = null,
    ): PublishProjectSourceResult {
        $source = ($this->localSourceInspector ?? new InspectLocalProjectSourceAction())($module);
        $this->assertExpectedLocalSource($source, $expectedSource);
        $staging = $this->temporaryDirectory('drupalorg-publish-');
        try {
            $urls = $this->urls ?? ProjectRepositoryUrls::forDrupalOrg($machineName);
            $this->run($staging, ['init']);
            $this->run($staging, ['remote', 'add', 'read', $urls->readUrl]);
            $this->run($staging, ['remote', 'add', 'drupal', $urls->writeUrl]);
            $branchProbe = $this->run($staging, ['ls-remote', '--exit-code', '--heads', 'read', $branch->name], false);
            if (!$branchProbe->isSuccessful() && $branchProbe->getExitCode() !== 2) {
                throw new \RuntimeException('Could not inspect Drupal.org branch: ' . trim($branchProbe->getErrorOutput()));
            }
            $branchExists = $branchProbe->isSuccessful();
            $this->fetchAndValidateExpectedBranch($staging, $branch, $expectedSource, $branchExists);
            if ($branchExists) {
                $this->mustRun($staging, ['checkout', '-B', $branch->name, 'FETCH_HEAD']);
                $this->mustRun($staging, ['rm', '-r', '--ignore-unmatch', '.']);
            }
            $this->archive($source->gitRoot, $source->modulePrefix, $staging);
            $this->mustRun($staging, ['add', '-A']);
            $diff = $this->run($staging, ['diff', '--cached', '--quiet'], false);
            if ($diff->getExitCode() === 0 && $branchExists) {
                return new PublishProjectSourceResult($branch->name, $source->sourceCommitSha, $this->git($staging, ['rev-parse', 'HEAD']), 0, false, true);
            }
            if ($diff->getExitCode() !== 1) {
                throw new \RuntimeException('Could not inspect staged Drupal.org source changes.');
            }
            [$name, $email] = $this->identity($source->gitRoot);
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
                throw new \RuntimeException($this->pushFailureMessage($push->getErrorOutput()));
            }
            return new PublishProjectSourceResult($branch->name, $source->sourceCommitSha, $this->git($staging, ['rev-parse', 'HEAD']), $filesChanged, !$branchExists, false);
        } finally {
            $this->removeDirectory($staging, 'drupalorg-publish-');
        }
    }

    private function assertExpectedLocalSource(
        ProjectLocalSourceResult $source,
        ?ProjectSourceStatusResult $expected,
    ): void {
        if ($expected !== null
            && ($expected->localSourceCommitSha !== $source->sourceCommitSha || $expected->localTreeSha !== $source->sourceTreeSha)) {
            throw new \RuntimeException("Module source changed during publication.\n\nRun project:publish again to review and publish the current committed source.");
        }
    }

    /** @return array{string, string} */
    private function identity(string $root): array
    {
        $nameProcess = $this->run($root, ['config', '--get', 'user.name'], false);
        $emailProcess = $this->run($root, ['config', '--get', 'user.email'], false);
        $name = trim($nameProcess->getOutput());
        $email = trim($emailProcess->getOutput());
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

    private function fetchAndValidateExpectedBranch(
        string $staging,
        ProjectReleaseBranch $branch,
        ?ProjectSourceStatusResult $expected,
        bool $currentExists,
    ): void {
        if ($expected !== null && $expected->branchExists !== $currentExists) {
            throw new \RuntimeException("Drupal.org branch changed after source inspection.\n\nThe publication plan is stale.\nRun project:publish again to review the new remote changes.");
        }
        if (!$currentExists) {
            return;
        }
        $this->mustRun($staging, ['fetch', '--no-tags', 'read', 'refs/heads/' . $branch->name]);
        if ($expected === null) {
            return;
        }
        $currentTree = $this->git($staging, ['rev-parse', 'FETCH_HEAD^{tree}']);
        if ($expected->branchTreeSha === null || !hash_equals($expected->branchTreeSha, $currentTree)) {
            throw new \RuntimeException("Drupal.org branch changed after source inspection.\n\nThe publication plan is stale.\nRun project:publish again to review the new remote changes.");
        }
    }

    private function pushFailureMessage(string $error): string
    {
        return self::classifyPushFailure($error);
    }

    public static function classifyPushFailure(string $error): string
    {
        $lower = strtolower($error);
        if (str_contains($lower, 'permission denied') || str_contains($lower, 'publickey')
            || str_contains($lower, 'authentication') || str_contains($lower, 'could not read from remote repository')) {
            return "Could not authenticate to Drupal.org Git.\n\nVerify SSH access with:\n  ssh -T git@git.drupal.org";
        }
        if (str_contains($lower, 'non-fast-forward') || str_contains($lower, 'fetch first')
            || str_contains($lower, 'rejected') || str_contains($lower, 'stale info')) {
            return "Drupal.org branch changed while publishing.\n\nRun project:publish again to inspect the current remote state.";
        }
        $safe = self::redactDiagnostic($error);
        return 'Could not publish source to Drupal.org: ' . ($safe === null || $safe === '' ? 'unknown Git error' : $safe);
    }

    private static function redactDiagnostic(string $error): ?string
    {
        $safe = preg_replace('/(token|password|secret|authorization)\s*[:=]\s*\S+/i', '$1=[redacted]', trim($error));
        return $safe === null ? null : preg_replace('#//[^/\s:@]+:[^/\s@]+@#', '//[redacted]@', $safe);
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
