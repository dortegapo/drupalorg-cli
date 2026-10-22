<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Project\DrupalOrgProjectSearch;
use mglaman\DrupalOrg\Result\Project\ProjectSearchResult;

final class SearchProjectsAction implements ActionInterface
{
    public function __construct(private readonly Client $client)
    {
    }

    public function __invoke(string $query, int $limit = 10): ProjectSearchResult
    {
        return new ProjectSearchResult(
            query: $query,
            projects: (new DrupalOrgProjectSearch($this->client))->search($query, $limit),
        );
    }
}
