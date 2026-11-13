<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Entity\Project;

final class ProjectCreationHandoffResult
{
    public const FOUND = 'found';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?Project $project = null,
    ) {
    }
}
