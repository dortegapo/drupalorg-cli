<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Project\ProjectRepositoryUrls;
use mglaman\DrupalOrg\Result\Project\CreateProjectTagResult;
use mglaman\DrupalOrg\Result\Project\ProjectSourceStatusResult;
use Symfony\Component\Process\Process;

/** Creates an immutable tag only when it represents the requested source tree. */
class CreateProjectTagAction implements ActionInterface
{
    public function __construct(private readonly ?ProjectRepositoryUrls $urls = null)
    {
    }

    public function __invoke(
        string $machineName,
        ProjectReleaseBranch $branch,
        ProjectReleaseVersion $version,
        ProjectSourceStatusResult $source,
    ): CreateProjectTagResult {
        if ($source->tagExists) {
            if (!$source->tagMatchesSource) {
                throw new \RuntimeException(sprintf(
                    "Release tag %s already exists for different source code.\n\nRelease tags are immutable.\nUse a new release version or check out the source corresponding to %s.",
                    $version->value,
                    $version->value,
                ));
            }
            return new CreateProjectTagResult($version->value, $source->localSourceCommitSha ?? '', false);
        }
        if (!$source->branchExists || !$source->branchMatchesSource) {
            throw new \RuntimeException('The release branch does not match the committed local module source. Publish source first.');
        }

        $directory = $this->temporaryDirectory();
        try {
            $remote = ($this->urls ?? ProjectRepositoryUrls::forDrupalOrg($machineName))->writeUrl;
            $this->run($directory, ['init']);
            $this->run($directory, ['remote', 'add', 'drupal', $remote]);
            $this->run($directory, ['fetch', '--no-tags', 'drupal', 'refs/heads/' . $branch->name]);
            $branchCommit = trim($this->run($directory, ['rev-parse', 'FETCH_HEAD'])->getOutput());
            $branchTree = trim($this->run($directory, ['rev-parse', 'FETCH_HEAD^{tree}'])->getOutput());
            if (!hash_equals($source->localTreeSha, $branchTree)) {
                throw new \RuntimeException('Drupal.org branch changed while publishing. Fetch the current repository state and rerun.');
            }
            $this->run($directory, ['tag', $version->value, 'FETCH_HEAD']);
            $push = $this->run($directory, ['push', 'drupal', 'refs/tags/' . $version->value], false);
            if (!$push->isSuccessful()) {
                throw new \RuntimeException('Could not push release tag. Verify SSH access with: ssh -T git@git.drupal.org');
            }
            return new CreateProjectTagResult($version->value, $branchCommit, true);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /** @param list<string> $args */
    private function run(string $cwd, array $args, bool $throw = true): Process
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(60);
        $process->setEnv(array_merge($_ENV, $_SERVER, ['GIT_TERMINAL_PROMPT' => '0']));
        $process->run();
        if ($throw && !$process->isSuccessful()) {
            throw new \RuntimeException('Git tag operation failed: ' . trim($process->getErrorOutput()));
        }
        return $process;
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drupalorg-tag-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create temporary repository for release tag.');
        }
        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || !str_starts_with(basename($directory), 'drupalorg-tag-')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
