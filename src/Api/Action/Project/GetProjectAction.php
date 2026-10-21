<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use GuzzleHttp\Exception\RequestException;
use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Entity\Project;

final class GetProjectAction implements ActionInterface
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * A missing project is represented by null. Other Drupal.org failures are
     * deliberately rethrown so callers cannot mistake them for absence.
     */
    public function __invoke(string $machineName): ?Project
    {
        try {
            return $this->client->getProject($machineName);
        } catch (RequestException $exception) {
            if ($exception->getResponse()?->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }
    }
}
