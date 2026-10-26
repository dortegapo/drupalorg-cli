<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Project\ModuleReviewResult;

final class ModuleReviewWorkflowResult
{
    /**
     * @param array<string, mixed>|null $runtime
     * @param ModuleReviewResult|array<string, mixed>|null $analysisResult
     */
    public function __construct(
        public readonly bool $continuePublishing,
        public readonly ?array $runtime,
        ModuleReviewResult|array|null $analysisResult,
        public readonly string $sourceCoverage = 'full',
    ) {
        $this->analysisResult = $analysisResult === null
            ? null
            : ($analysisResult instanceof ModuleReviewResult
                ? $analysisResult
                : ModuleReviewResult::fromArray($analysisResult));
    }

    public readonly ?ModuleReviewResult $analysisResult;
}
