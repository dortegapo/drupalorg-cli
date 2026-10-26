<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Result\Project\GetProjectResult;

/** Looks up a project on Drupal.org without imposing CLI behavior. */
final class GetProjectAction implements ActionInterface
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    public function __invoke(string $machineName): GetProjectResult
    {
        return new GetProjectResult($machineName, $this->client->getProject($machineName));
    }
}
