<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

final class ModuleReviewWorkflowResult
{
    /**
     * @param array<string, mixed>|null $runtime
     * @param array<string, mixed>|null $analysisResult
     */
    public function __construct(
        public readonly bool $continuePublishing,
        public readonly ?array $runtime,
        public readonly ?array $analysisResult,
        public readonly string $sourceCoverage = 'full',
    ) {
    }
}
