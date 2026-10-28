<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Project\ModuleReviewResult;

final class ModuleReviewWorkflowResult
{
    /** @param array<string, mixed>|null $runtime */
    public function __construct(
        public readonly bool $continuePublishing,
        public readonly ?array $runtime,
        public readonly ?ModuleReviewResult $analysisResult,
        public readonly string $sourceCoverage = 'full',
    ) {
    }
}
