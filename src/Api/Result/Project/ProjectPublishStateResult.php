<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Result\ResultInterface;

/** Immutable source/release observations used to resume publication safely. */
final class ProjectPublishStateResult implements ResultInterface
{
    public function __construct(
        public readonly bool $repositoryReachable,
        public readonly ?ProjectReleaseBranch $branch = null,
        public readonly ?ProjectReleaseVersion $version = null,
        public readonly ?string $localSourceCommitSha = null,
        public readonly ?string $localTreeSha = null,
        public readonly bool $branchExists = false,
        public readonly ?string $branchTreeSha = null,
        public readonly bool $branchMatchesSource = false,
        public readonly bool $tagExists = false,
        public readonly ?string $tagTreeSha = null,
        public readonly bool $tagMatchesSource = false,
        public readonly bool $releaseExists = false,
        public readonly bool $composerPackageVisible = false,
        public readonly int $filesAdded = 0,
        public readonly int $filesModified = 0,
        public readonly int $filesDeleted = 0,
        public readonly ?string $branchCommitSha = null,
        public readonly ?string $tagCommitSha = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'repository_reachable' => $this->repositoryReachable,
            'branch' => $this->branch?->name,
            'version' => $this->version?->value,
            'local_source_commit_sha' => $this->localSourceCommitSha,
            'local_tree_sha' => $this->localTreeSha,
            'branch_exists' => $this->branchExists,
            'branch_tree_sha' => $this->branchTreeSha,
            'branch_matches_source' => $this->branchMatchesSource,
            'tag_exists' => $this->tagExists,
            'tag_tree_sha' => $this->tagTreeSha,
            'tag_matches_source' => $this->tagMatchesSource,
            'release_exists' => $this->releaseExists,
            'composer_package_visible' => $this->composerPackageVisible,
            'files_added' => $this->filesAdded,
            'files_modified' => $this->filesModified,
            'files_deleted' => $this->filesDeleted,
            'branch_commit_sha' => $this->branchCommitSha,
            'tag_commit_sha' => $this->tagCommitSha,
        ];
    }
}
