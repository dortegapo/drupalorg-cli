<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

/** Local Git snapshot and bootstrap state used for project publication. */
final class ProjectLocalSourceResult implements ResultInterface
{
    public readonly string $repositoryRoot;

    /**
     * @param list<string> $trackedFiles
     * @param list<string> $candidateFiles
     */
    public function __construct(
        public readonly string $gitRoot,
        public readonly string $modulePrefix,
        public readonly ?string $sourceCommitSha,
        public readonly ?string $sourceTreeSha,
        public readonly bool $repositoryExists = true,
        public readonly bool $repositoryHasHead = true,
        public readonly bool $moduleExistsInHead = true,
        public readonly bool $moduleClean = true,
        public readonly array $trackedFiles = [],
        public readonly bool $bootstrapRequired = false,
        public readonly ?string $bootstrapReason = null,
        public readonly array $candidateFiles = [],
        public readonly bool $stagedChangesOutsideModule = false,
        public readonly ?string $gitUserName = null,
        public readonly ?string $gitUserEmail = null,
    ) {
        $this->repositoryRoot = $gitRoot;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'repository_exists' => $this->repositoryExists,
            'repository_root' => $this->gitRoot,
            'git_root' => $this->gitRoot,
            'module_prefix' => $this->modulePrefix,
            'repository_has_head' => $this->repositoryHasHead,
            'module_exists_in_head' => $this->moduleExistsInHead,
            'module_clean' => $this->moduleClean,
            'source_commit_sha' => $this->sourceCommitSha,
            'source_tree_sha' => $this->sourceTreeSha,
            'tracked_files' => $this->trackedFiles,
            'candidate_files' => $this->candidateFiles,
            'bootstrap_required' => $this->bootstrapRequired,
            'bootstrap_reason' => $this->bootstrapReason,
            'staged_changes_outside_module' => $this->stagedChangesOutsideModule,
        ];
    }
}
