<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

final class ProjectSearchResult implements ResultInterface
{
    /**
     * @param list<array{machineName: string, title: string, description: string, url: string}> $projects
     */
    public function __construct(
        public readonly string $query,
        public readonly array $projects,
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return [
            'query' => $this->query,
            'projects' => $this->projects,
        ];
    }
}
