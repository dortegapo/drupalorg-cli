<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Result\Project\ProjectLocalSourceResult;
use Symfony\Component\Process\Process;

/** Reads the local module source state used for project publication. */
class InspectLocalProjectSourceAction implements ActionInterface
{
    public static function assertPublishable(ProjectLocalSourceResult $source): void
    {
        if (!$source->repositoryExists) {
            throw new \RuntimeException("Module source is not inside a Git repository.\n\nproject:publish only publishes committed Git source.");
        }
        if (!$source->repositoryHasHead) {
            throw new \RuntimeException("Module source has no Git commit yet.\n\nproject:publish only publishes committed source.\n\nCommit the module before publishing, then run project:publish again.");
        }
        if (!$source->moduleExistsInHead) {
            throw new \RuntimeException("Module source is not committed in HEAD.\n\nCommit the module before publishing.");
        }
        if (!$source->moduleClean) {
            throw new \RuntimeException("Module source contains uncommitted changes.\n\nCommit or discard them before publishing.");
        }
        if ($source->sourceCommitSha === null || $source->sourceTreeSha === null) {
            throw new \RuntimeException('Could not determine the committed local module source.');
        }
    }

    public function __invoke(ModuleContext $module): ProjectLocalSourceResult
    {
        $modulePath = $module->location->resolvedPath;
        $rootProcess = $this->run($modulePath, ['rev-parse', '--show-toplevel'], false);
        if (!$rootProcess->isSuccessful()) {
            $files = $this->filesystemFiles($modulePath);
            $this->validateCandidateFiles($modulePath, $files);
            $this->assertComposer($module);

            return new ProjectLocalSourceResult(
                '',
                '',
                null,
                null,
                false,
                false,
                false,
                true,
                [],
                true,
                'repository_missing',
                $files,
            );
        }

        $root = trim($rootProcess->getOutput());
        $prefix = trim($this->run($modulePath, ['rev-parse', '--show-prefix'])->getOutput());
        $head = $this->run($root, ['rev-parse', '--verify', 'HEAD'], false);
        $repositoryHasHead = $head->isSuccessful();
        $commit = $repositoryHasHead ? trim($head->getOutput()) : null;
        $path = $prefix === '' ? '.' : $prefix;
        $moduleTree = $repositoryHasHead
            ? $this->run($root, ['rev-parse', '--verify', $prefix === '' ? 'HEAD^{tree}' : 'HEAD:' . rtrim($prefix, '/')], false)
            : null;
        $moduleExistsInHead = $moduleTree?->isSuccessful() ?? false;
        $tree = $moduleExistsInHead ? trim($moduleTree->getOutput()) : null;
        $status = $this->run($root, ['status', '--porcelain', '--', $path]);
        $moduleClean = trim($status->getOutput()) === '';
        $trackedFiles = $this->paths($this->run($root, ['ls-files', '-z', '--', $path])->getOutput());
        $candidateFiles = $this->paths($this->run(
            $root,
            ['ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', $path],
        )->getOutput());
        $stagedChangesOutsideModule = $this->hasStagedChangesOutsideModule($root, $prefix);
        [$userName, $userEmail] = $this->identity($root);
        $this->assertComposer($module);
        if ($moduleExistsInHead && $moduleClean) {
            $this->validateCandidateFiles($root, $trackedFiles);
        } elseif (!$moduleExistsInHead) {
            $this->validateCandidateFiles($root, $candidateFiles);
        }

        $bootstrapReason = null;
        if (!$repositoryHasHead) {
            $bootstrapReason = 'repository_unborn';
        } elseif (!$moduleExistsInHead) {
            $bootstrapReason = 'module_missing_from_head';
        }

        return new ProjectLocalSourceResult(
            $root,
            $prefix,
            $commit,
            $tree,
            true,
            $repositoryHasHead,
            $moduleExistsInHead,
            $moduleClean,
            $trackedFiles,
            $bootstrapReason !== null,
            $bootstrapReason,
            $candidateFiles,
            $stagedChangesOutsideModule,
            $userName,
            $userEmail,
        );
    }

    /**
     * Validate files that would be eligible for a bootstrap commit.
     *
     * @param list<string> $files
     */
    public function validateCandidateFiles(string $root, array $files): void
    {
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

    private function assertComposer(ModuleContext $module): void
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
        if (!is_array($composer)
            || ($composer['name'] ?? null) !== 'drupal/' . $module->location->machineName
            || ($composer['type'] ?? null) !== 'drupal-module') {
            throw new \RuntimeException(sprintf(
                'Module composer.json must declare name "drupal/%s" and type "drupal-module" before publishing.',
                $module->location->machineName,
            ));
        }
    }

    /** @return array{?string, ?string} */
    private function identity(string $root): array
    {
        $name = $this->run($root, ['config', '--get', 'user.name'], false);
        $email = $this->run($root, ['config', '--get', 'user.email'], false);

        return [
            trim($name->getOutput()) === '' ? null : trim($name->getOutput()),
            trim($email->getOutput()) === '' ? null : trim($email->getOutput()),
        ];
    }

    private function hasStagedChangesOutsideModule(string $root, string $prefix): bool
    {
        foreach ($this->paths($this->run($root, ['diff', '--cached', '--name-only', '-z'])->getOutput()) as $path) {
            if (!$this->isWithinModule($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isWithinModule(string $path, string $prefix): bool
    {
        $prefix = rtrim($prefix, '/');
        return $prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    /** @return list<string> */
    private function paths(string $output): array
    {
        return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
    }

    /** @return list<string> */
    private function filesystemFiles(string $directory): array
    {
        $gitDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drupalorg-source-inspect-' . bin2hex(random_bytes(8));
        if (!mkdir($gitDirectory, 0700)) {
            throw new \RuntimeException('Could not prepare local Git source inspection.');
        }
        try {
            $this->run($directory, ['init', '--bare', $gitDirectory]);
            return $this->paths($this->run(
                $directory,
                ['--git-dir=' . $gitDirectory, '--work-tree=' . $directory, 'ls-files', '--others', '--exclude-standard', '-z', '--', '.'],
            )->getOutput());
        } finally {
            $this->removeDirectory($gitDirectory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || !str_starts_with(basename($directory), 'drupalorg-source-inspect-')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    /** @param list<string> $args */
    private function run(string $cwd, array $args, bool $throw = true): Process
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(60);
        $process->setEnv(array_merge($_ENV, $_SERVER, ['GIT_TERMINAL_PROMPT' => '0']));
        $process->run();
        if ($throw && !$process->isSuccessful()) {
            throw new \RuntimeException('Could not inspect local Git source: ' . trim($process->getErrorOutput()));
        }

        return $process;
    }
}
