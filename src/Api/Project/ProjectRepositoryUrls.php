<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/** Read and maintainer-write URLs for a Drupal.org project repository. */
final class ProjectRepositoryUrls
{
    public function __construct(
        public readonly string $readUrl,
        public readonly string $writeUrl,
    ) {
    }

    public static function forDrupalOrg(string $machineName): self
    {
        return new self(
            'https://git.drupalcode.org/project/' . $machineName . '.git',
            'git@git.drupal.org:project/' . $machineName . '.git',
        );
    }
}
