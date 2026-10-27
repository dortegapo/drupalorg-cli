<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Result\Project\CreateProjectTagResult;
use mglaman\DrupalOrg\Result\Project\ProjectRepositoryResult;
use Symfony\Component\Process\Process;

/** Creates an immutable lightweight release tag on the published branch. */
final class CreateProjectTagAction implements ActionInterface
{
    public function __invoke(
        string $machineName,
        ProjectReleaseBranch $branch,
        ProjectReleaseVersion $version,
        ProjectRepositoryResult $repository,
    ): CreateProjectTagResult {
        $branchSha = $repository->branchSha($branch->name);
        if ($branchSha === null) {
            throw new \RuntimeException('The selected release branch does not exist on Drupal.org. Publish source first.');
        }
        $existingTagSha = $repository->tagSha($version->value);
        if ($existingTagSha !== null) {
            if ($existingTagSha !== $branchSha) {
                throw new \RuntimeException(sprintf('Release tag %s already exists on a different commit. Release tags are never moved.', $version->value));
            }
            return new CreateProjectTagResult($version->value, $branchSha, false);
        }

        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drupalorg-tag-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create temporary repository for release tag.');
        }
        try {
            $remote = 'git@git.drupal.org:project/' . $machineName . '.git';
            $this->run($directory, ['init']);
            $this->run($directory, ['remote', 'add', 'drupal', $remote]);
            $this->run($directory, ['fetch', '--no-tags', 'drupal', 'refs/heads/' . $branch->name]);
            $this->run($directory, ['tag', $version->value, 'FETCH_HEAD']);
            $this->run($directory, ['push', 'drupal', 'refs/tags/' . $version->value], 'Could not push release tag. Verify SSH access with: ssh -T git@git.drupal.org');
            return new CreateProjectTagResult($version->value, $branchSha, true);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /** @param list<string> $args */
    private function run(string $cwd, array $args, ?string $failure = null): void
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(60);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($failure ?? 'Git tag operation failed.');
        }
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
