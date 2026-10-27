<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

/** Validated local Git snapshot used for project publication. */
final class ProjectLocalSourceResult implements ResultInterface
{
    public function __construct(
        public readonly string $gitRoot,
        public readonly string $modulePrefix,
        public readonly string $sourceCommitSha,
        public readonly string $sourceTreeSha,
    ) {
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return [
            'git_root' => $this->gitRoot,
            'module_prefix' => $this->modulePrefix,
            'source_commit_sha' => $this->sourceCommitSha,
            'source_tree_sha' => $this->sourceTreeSha,
        ];
    }
}
