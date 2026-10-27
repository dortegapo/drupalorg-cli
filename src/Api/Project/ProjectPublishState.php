<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/** Immutable source/release observations used to resume publication safely. */
final class ProjectPublishState
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
}
