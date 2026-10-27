<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/** Immutable observation of a resumable project-publication workflow. */
final class ProjectPublishState
{
    public function __construct(
        public readonly bool $moduleResolved,
        public readonly bool $projectExists,
        public readonly bool $repositoryReachable,
        public readonly ?ProjectReleaseBranch $branch = null,
        public readonly ?ProjectReleaseVersion $version = null,
        public readonly ?string $remoteBranchSha = null,
        public readonly ?string $remoteTagSha = null,
        public readonly bool $sourcePublished = false,
        public readonly bool $tagExists = false,
        public readonly bool $releaseExists = false,
        public readonly ?string $releaseId = null,
        public readonly bool $composerPackageVisible = false,
    ) {
    }
}
