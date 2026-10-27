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
        $branchCommit = null;
        $branchTree = null;
        $tagCommit = null;
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
                    $branchCommit = $this->git($directory, ['rev-parse', 'FETCH_HEAD^{commit}']);
                    $branchTree = $this->git($directory, ['rev-parse', 'FETCH_HEAD^{tree}']);
                    [$added, $modified, $deleted] = $this->changeSummary($root, $prefix, $directory);
                }
                if ($repository->tagSha($version->value) !== null) {
                    $this->run($directory, ['fetch', '--no-tags', 'read', 'refs/tags/' . $version->value]);
                    $tagCommit = $this->git($directory, ['rev-parse', 'FETCH_HEAD^{commit}']);
                    $tagTree = $this->git($directory, ['rev-parse', 'FETCH_HEAD^{tree}']);
                }
            } finally {
                $this->removeDirectory($directory);
            }
        }
        if ($branchCommit === null) {
            $localTree = $prefix === '' ? 'HEAD' : 'HEAD:' . rtrim($prefix, '/');
            $added = count($this->treeEntries($root, $localTree));
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
            $branchCommit,
            $tagCommit,
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
        $process->setEnv(array_merge($_ENV, $_SERVER, ['GIT_TERMINAL_PROMPT' => '0']));
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
        $localTree = $prefix === '' ? 'HEAD' : 'HEAD:' . rtrim($prefix, '/');
        $localFiles = $this->treeEntries($root, $localTree);
        $remoteFiles = $this->treeEntries($repository, 'FETCH_HEAD');
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
    private function treeEntries(string $cwd, string $tree): array
    {
        $entries = [];
        $output = $this->git($cwd, ['ls-tree', '-r', $tree]);
        $lines = preg_split('/\R/', $output);
        foreach ($lines === false ? [] : $lines as $line) {
            if ($line === '' || !str_contains($line, "\t")) {
                continue;
            }
            [$metadata, $path] = explode("\t", $line, 2);
            $parts = preg_split('/\s+/', $metadata);
            if ($parts !== false && count($parts) >= 3) {
                $entries[$path] = $parts[0] . ':' . $parts[2];
            }
        }
        return $entries;
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
