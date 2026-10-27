<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Project\ProjectRepositoryUrls;
use mglaman\DrupalOrg\Result\Project\ProjectRepositoryResult;
use mglaman\DrupalOrg\Result\Project\ProjectSourceStatusResult;
use Symfony\Component\Process\Process;

/** Compares committed local module content with remote branch/tag trees. */
class InspectProjectSourceAction implements ActionInterface
{
    public function __construct(private readonly ?ProjectRepositoryUrls $urls = null)
    {
    }

    public function __invoke(
        ModuleContext $module,
        ProjectReleaseBranch $branch,
        ProjectReleaseVersion $version,
        ProjectRepositoryResult $repository,
    ): ProjectSourceStatusResult {
        $root = $this->git($module->location->resolvedPath, ['rev-parse', '--show-toplevel']);
        $prefix = trim($this->git($module->location->resolvedPath, ['rev-parse', '--show-prefix']));
        $commit = $this->git($root, ['rev-parse', 'HEAD']);
        $tree = $this->git($root, ['rev-parse', $prefix === '' ? 'HEAD^{tree}' : 'HEAD:' . rtrim($prefix, '/')]);
        $branchTree = null;
        $tagTree = null;
        $added = 0;
        $modified = 0;
        $deleted = 0;
        if ($repository->branchSha($branch->name) !== null || $repository->tagSha($version->value) !== null) {
            $directory = $this->temporaryDirectory();
            try {
                $remote = ($this->urls ?? ProjectRepositoryUrls::forDrupalOrg($module->location->machineName))->readUrl;
                $this->run($directory, ['init']);
                $this->run($directory, ['remote', 'add', 'read', $remote]);
                if ($repository->branchSha($branch->name) !== null) {
                    $this->run($directory, ['fetch', '--no-tags', 'read', 'refs/heads/' . $branch->name]);
                    $branchTree = $this->git($directory, ['rev-parse', 'FETCH_HEAD^{tree}']);
                    [$added, $modified, $deleted] = $this->changeSummary($root, $prefix, $directory);
                }
                if ($repository->tagSha($version->value) !== null) {
                    $this->run($directory, ['fetch', '--no-tags', 'read', 'refs/tags/' . $version->value]);
                    $tagTree = $this->git($directory, ['rev-parse', 'FETCH_HEAD^{tree}']);
                }
            } finally {
                $this->removeDirectory($directory);
            }
        }

        return new ProjectSourceStatusResult(
            $commit,
            $tree,
            $repository->branchSha($branch->name) !== null,
            $branchTree,
            $branchTree !== null && hash_equals($tree, $branchTree),
            $repository->tagSha($version->value) !== null,
            $tagTree,
            $tagTree !== null && hash_equals($tree, $tagTree),
            $added,
            $modified,
            $deleted,
        );
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): string
    {
        $process = $this->run($cwd, $args);
        return trim($process->getOutput());
    }

    /** @param list<string> $args */
    private function run(string $cwd, array $args): Process
    {
        $process = new Process(array_merge(['git'], $args), $cwd, null, null, 60);
        $process->setEnv(['GIT_TERMINAL_PROMPT' => '0']);
        $process->mustRun();
        return $process;
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drupalorg-source-status-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create temporary source inspection repository.');
        }
        return $directory;
    }

    /** @return array{int, int, int} */
    private function changeSummary(string $root, string $prefix, string $repository): array
    {
        $localArchive = $repository . DIRECTORY_SEPARATOR . 'local.tar';
        $remoteArchive = $repository . DIRECTORY_SEPARATOR . 'remote.tar';
        $tree = $prefix === '' ? 'HEAD' : 'HEAD:' . rtrim($prefix, '/');
        $this->run($root, ['archive', '--format=tar', '--output=' . $localArchive, $tree]);
        $this->run($repository, ['archive', '--format=tar', '--output=' . $remoteArchive, 'FETCH_HEAD']);
        $local = $repository . DIRECTORY_SEPARATOR . 'local';
        $remote = $repository . DIRECTORY_SEPARATOR . 'remote';
        mkdir($local);
        mkdir($remote);
        (new \PharData($localArchive))->extractTo($local, null, true);
        (new \PharData($remoteArchive))->extractTo($remote, null, true);
        unlink($localArchive);
        unlink($remoteArchive);
        $localFiles = $this->files($local);
        $remoteFiles = $this->files($remote);
        $added = count(array_diff_key($localFiles, $remoteFiles));
        $deleted = count(array_diff_key($remoteFiles, $localFiles));
        $modified = 0;
        foreach (array_intersect_key($localFiles, $remoteFiles) as $path => $hash) {
            if ($hash !== $remoteFiles[$path]) {
                $modified++;
            }
        }
        return [$added, $modified, $deleted];
    }

    /** @return array<string, string> */
    private function files(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($directory) + 1);
                $hash = sha1_file($file->getPathname());
                $files[$relative] = $hash === false ? '' : $hash;
            }
        }
        return $files;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || !str_starts_with(basename($directory), 'drupalorg-source-status-')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
