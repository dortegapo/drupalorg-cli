<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Entity\Project;
use mglaman\DrupalOrg\Result\ResultInterface;

/** Result of a Drupal.org project lookup. */
final class GetProjectResult implements ResultInterface
{
    public function __construct(
        public readonly string $machineName,
        public readonly ?Project $project,
    ) {
    }

    public function found(): bool
    {
        return $this->project !== null;
    }

    /** @return array{machine_name: string, found: bool, project: array<string, mixed>|null} */
    public function jsonSerialize(): array
    {
        return [
            'machine_name' => $this->machineName,
            'found' => $this->found(),
            'project' => $this->project === null ? null : [
                'nid' => $this->project->nid,
                'title' => $this->project->title,
                'machine_name' => $this->project->machineName,
                'has_issue_queue' => $this->project->hasIssueQueue,
            ],
        ];
    }
}
