<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use mglaman\DrupalOrg\Client;

final class ModuleReviewAnalyzerFactory
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    public function create(): ModuleReviewAnalyzer
    {
        return new ModuleReviewAnalyzer(
            new ModuleReviewCollector(),
            new ModuleReviewSensitiveDataSanitizer(),
            new ModuleReviewPromptBuilder(),
            new ModuleReviewResultValidator(),
            new DrupalAiBridge(),
            new DrupalOrgProjectSearch($this->client),
            new ModuleReviewCandidateRanker(),
            new ModuleReviewComparisonPromptBuilder(),
            new ModuleReviewComparisonResultValidator(),
            new ModuleContributionAssessmentResolver(),
            new DrupalOrgProjectCompatibilityResolver($this->client),
        );
    }
}
