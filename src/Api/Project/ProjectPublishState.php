<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use mglaman\DrupalOrg\Entity\Project;

/**
 * Resumable publication state for the stages currently supported by the CLI.
 *
 * Future repository, tag, release, and Composer stages can extend this model
 * without turning the command into a chain of unrelated boolean branches.
 */
final class ProjectPublishState
{
    public function __construct(
        public readonly ModuleContext $module,
        public readonly ?Project $project = null,
        public readonly bool $projectCreationVerified = false,
    ) {
    }

    public function projectExists(): bool
    {
        return $this->project !== null;
    }

    public function withProject(?Project $project): self
    {
        return new self($this->module, $project, $this->projectCreationVerified);
    }

    public function markProjectCreationVerified(): self
    {
        return new self($this->module, $this->project, true);
    }
}
