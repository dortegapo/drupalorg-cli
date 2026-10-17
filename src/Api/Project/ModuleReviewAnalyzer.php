<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class ModuleReviewAnalyzer
{
    private const SEARCH_LIMIT = 10;

    private const MAXIMUM_RANKED_CANDIDATES = 100;

    /**
     * @var array<string, string>
     */
    private const LIMITATION_MESSAGES = [
        'collection' => 'Module source collection could not be completed.',
        'sanitization' => 'Module source sanitization could not be completed.',
        'source_empty' => 'No analyzable module source files were found.',
        'review_prompt' => 'The module review prompt could not be built.',
        'review_ai' => 'The module review could not be completed by Drupal AI.',
        'review_validation' => 'The Drupal AI module review result was invalid.',
        'project_search' => 'Drupal.org project search could not be completed.',
        'candidate_ranking' => 'Module review candidate ranking could not be completed.',
        'comparison_prompt' => 'The module review comparison prompt could not be built.',
        'comparison_ai' => 'The module review comparison could not be completed by Drupal AI.',
        'comparison_validation' => 'The Drupal AI module comparison result was invalid.',
        'assessment' => 'The module contribution assessment could not be completed.',
        'core_version' => 'The Drupal runtime core version could not be determined.',
        'compatibility' => 'Drupal.org core compatibility could not be verified for one or more candidates.',
    ];

    public function __construct(
        private readonly ModuleReviewCollector $collector,
        private readonly ModuleReviewSensitiveDataSanitizer $sanitizer,
        private readonly ModuleReviewPromptBuilder $reviewPromptBuilder,
        private readonly ModuleReviewResultValidator $reviewValidator,
        private readonly DrupalAiBridge $aiBridge,
        private readonly DrupalOrgProjectSearch $projectSearch,
        private readonly ModuleReviewCandidateRanker $candidateRanker,
        private readonly ModuleReviewComparisonPromptBuilder $comparisonPromptBuilder,
        private readonly ModuleReviewComparisonResultValidator $comparisonValidator,
        private readonly ModuleContributionAssessmentResolver $assessmentResolver,
        private readonly ?DrupalCoreVersionInspector $coreVersionInspector = null,
        private readonly ?DrupalOrgProjectCompatibilityResolver $compatibilityResolver = null,
    ) {
    }

    /**
     * Analyze a module already located by the caller.
     *
     * Expected operational failures are returned as an incomplete analysis.
     * Invalid arguments and programming errors are intentionally not hidden.
     *
     * @param array{
     *     type: 'ddev'|'lando'|'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * } $runtime
     *
     * @return array<string, mixed>
     */
    public function analyze(
        string $modulePath,
        string $machineName,
        array $runtime,
        ?callable $progress = null,
        ?string $humanName = null,
    ): array {
        $module = [
            'machineName' => $machineName,
            'path' => $modulePath,
        ];
        $source = $this->emptySource();
        $ai = [
            'review' => null,
            'comparison' => null,
        ];

        try {
            $collection = $this->collector->collect($modulePath);
        } catch (\RuntimeException) {
            return $this->incomplete($module, $source, null, [], [], null, $ai, 'collection');
        }

        $source['files'] = count($collection['files']);
        $source['skipped'] = $collection['skipped'];

        try {
            $sanitized = $this->sanitizer->sanitize($collection['files']);
        } catch (\RuntimeException) {
            return $this->incomplete($module, $source, null, [], [], null, $ai, 'sanitization');
        }

        $sanitizedCollection = [
            'files' => $sanitized['files'],
            'skipped' => $collection['skipped'],
            'totalBytes' => array_sum(array_column($sanitized['files'], 'bytes')),
        ];
        $source['bytes'] = $sanitizedCollection['totalBytes'];
        $source['redactions'] = $sanitized['redactions'];
        $this->emitProgress($progress, 'source_prepared', [
            'files' => count($sanitized['files']),
            'bytes' => $source['bytes'],
            'redactions' => count($source['redactions']),
        ]);

        if ($sanitized['files'] === []) {
            return $this->incomplete($module, $source, null, [], [], null, $ai, 'source_empty');
        }

        try {
            $reviewPrompt = $this->reviewPromptBuilder->build($machineName, $sanitizedCollection);
        } catch (\RuntimeException) {
            return $this->incomplete($module, $source, null, [], [], null, $ai, 'review_prompt');
        }

        $this->emitProgress($progress, 'review_ai_started');
        try {
            $reviewAi = $this->aiBridge->structuredChat(
                $runtime,
                $reviewPrompt['systemPrompt'],
                $reviewPrompt['userPrompt'],
                $reviewPrompt['schema'],
                $this->waitCallback($progress, 'review_ai_tick'),
            );
        } catch (\RuntimeException) {
            $this->emitProgress($progress, 'review_ai_failed');
            return $this->incomplete($module, $source, null, [], [], null, $ai, 'review_ai');
        }

        $this->emitProgress($progress, 'review_ai_completed', [
            'providerId' => $reviewAi['providerId'],
            'modelId' => $reviewAi['modelId'],
        ]);

        $ai['review'] = $this->aiMetadata($reviewAi);
        try {
            $review = $this->reviewValidator->validate($reviewAi['result'], $sanitized['files'], $machineName);
        } catch (\RuntimeException) {
            return $this->incomplete($module, $source, null, [], [], null, $ai, 'review_validation');
        }

        $searches = [];
        $primaryRankings = [];
        $secondaryRankings = [];
        $searchPlan = $this->buildSearchPlan($review, $humanName);

        $searchTotal = count($searchPlan);
        $this->emitProgress($progress, 'project_search_started', ['total' => $searchTotal]);
        $searchCurrent = 0;
        foreach ($searchPlan as $searchItem) {
            $term = $searchItem['term'];
            $type = $searchItem['type'];
            try {
                $projects = $this->projectSearch->search($term, self::SEARCH_LIMIT, 0);
            } catch (\RuntimeException) {
                $this->emitProgress($progress, 'project_search_failed', [
                    'current' => $searchCurrent + 1,
                    'total' => $searchTotal,
                    'term' => $term,
                    'type' => $type,
                ]);
                return $this->incomplete(
                    $module,
                    $source,
                    $review,
                    $searches,
                    [],
                    null,
                    $ai,
                    'project_search',
                );
            }

            ++$searchCurrent;
            $searches[] = [
                'type' => $type,
                'term' => $term,
                'results' => $projects,
            ];
            $ranking = [
                'term' => $term,
                'projects' => $projects,
            ];
            if (in_array($type, ['name', 'core', 'functional'], true)) {
                $primaryRankings[] = $ranking;
            } else {
                $secondaryRankings[] = $ranking;
            }
            $this->emitProgress($progress, 'project_search_progress', [
                'current' => $searchCurrent,
                'total' => $searchTotal,
                'term' => $term,
                'type' => $type,
            ]);
        }
        $this->emitProgress($progress, 'project_search_completed', [
            'total' => $searchTotal,
        ]);

        try {
            $candidates = $this->candidateRanker->rank(
                $primaryRankings,
                $secondaryRankings,
                self::MAXIMUM_RANKED_CANDIDATES,
            );
        } catch (\RuntimeException) {
            return $this->incomplete(
                $module,
                $source,
                $review,
                $searches,
                [],
                null,
                $ai,
                'candidate_ranking',
            );
        }
        $this->emitProgress($progress, 'candidate_ranking_completed', [
            'candidateCount' => count($candidates),
        ]);

        try {
            $comparisonPrompt = $this->comparisonPromptBuilder->build($review, $candidates);
        } catch (\RuntimeException) {
            return $this->incomplete(
                $module,
                $source,
                $review,
                $searches,
                $candidates,
                null,
                $ai,
                'comparison_prompt',
            );
        }

        $this->emitProgress($progress, 'comparison_ai_started', [
            'candidateCount' => count($candidates),
        ]);
        try {
            $comparisonAi = $this->aiBridge->structuredChat(
                $runtime,
                $comparisonPrompt['systemPrompt'],
                $comparisonPrompt['userPrompt'],
                $comparisonPrompt['schema'],
                $this->waitCallback($progress, 'comparison_ai_tick'),
            );
        } catch (\RuntimeException) {
            $this->emitProgress($progress, 'comparison_ai_failed');
            return $this->incomplete(
                $module,
                $source,
                $review,
                $searches,
                $candidates,
                null,
                $ai,
                'comparison_ai',
            );
        }
        $this->emitProgress($progress, 'comparison_ai_completed', [
            'providerId' => $comparisonAi['providerId'],
            'modelId' => $comparisonAi['modelId'],
        ]);

        $ai['comparison'] = $this->aiMetadata($comparisonAi);
        try {
            $comparison = $this->comparisonValidator->validate($comparisonAi['result'], $candidates);
        } catch (\RuntimeException) {
            return $this->incomplete(
                $module,
                $source,
                $review,
                $searches,
                $candidates,
                null,
                $ai,
                'comparison_validation',
            );
        }

        try {
            $assessment = $this->assessmentResolver->resolve($review, $comparison);
        } catch (\RuntimeException) {
            return $this->incomplete(
                $module,
                $source,
                $review,
                $searches,
                $candidates,
                $comparison,
                $ai,
                'assessment',
            );
        }

        $this->emitProgress($progress, 'assessment_completed', [
            'status' => $assessment['status'],
        ]);

        $core = [
            'runtimeVersion' => null,
            'targetMajor' => null,
        ];
        $compatibility = [];
        $compatibilityLimitations = [];
        if ($this->coreVersionInspector !== null) {
            $this->emitProgress($progress, 'core_version_started');
            try {
                $inspectedCore = $this->coreVersionInspector->inspect($runtime);
                if ($inspectedCore !== null) {
                    $core = [
                        'runtimeVersion' => $inspectedCore['version'],
                        'targetMajor' => $inspectedCore['major'],
                    ];
                } else {
                    $compatibilityLimitations[] = [
                        'stage' => 'core_version',
                        'message' => self::LIMITATION_MESSAGES['core_version'],
                    ];
                }
            } catch (\Throwable) {
                $compatibilityLimitations[] = [
                    'stage' => 'core_version',
                    'message' => self::LIMITATION_MESSAGES['core_version'],
                ];
            }
            $this->emitProgress($progress, 'core_version_completed', $core);
        }

        if ($this->compatibilityResolver !== null) {
            $comparisonCandidates = $comparison['candidates'];
            $compatibilityTotal = count($comparisonCandidates);
            $this->emitProgress($progress, 'compatibility_started', [
                'current' => 0,
                'total' => $compatibilityTotal,
            ]);
            $compatibilityCurrent = 0;
            foreach ($comparisonCandidates as $comparisonCandidate) {
                $candidateMachineName = $comparisonCandidate['machine_name'];
                try {
                    $compatibility[$candidateMachineName] = $this->compatibilityResolver->resolve(
                        $candidateMachineName,
                        $core,
                    );
                } catch (\Throwable) {
                    $compatibility[$candidateMachineName] = $this->compatibilityResolver->unknown($core);
                    $compatibilityLimitations[] = [
                        'stage' => 'compatibility',
                        'message' => self::LIMITATION_MESSAGES['compatibility'],
                    ];
                }
                ++$compatibilityCurrent;
                $this->emitProgress($progress, 'compatibility_progress', [
                    'current' => $compatibilityCurrent,
                    'total' => $compatibilityTotal,
                    'machineName' => $candidateMachineName,
                ]);
            }
            $this->emitProgress($progress, 'compatibility_completed', [
                'current' => $compatibilityCurrent,
                'total' => $compatibilityTotal,
            ]);
        }

        $result = [
            'complete' => true,
            'module' => $module,
            'source' => $source,
            'review' => $review,
            'searches' => $searches,
            'candidates' => $candidates,
            'comparison' => $comparison,
            'assessment' => $assessment,
            'core' => $core,
            'candidateCompatibility' => $compatibility,
            'ai' => $ai,
            'limitations' => $compatibilityLimitations,
        ];
        $this->emitProgress($progress, 'analysis_completed', [
            'complete' => true,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $module
     * @param array<string, mixed> $source
     * @param array<string, mixed>|null $review
     * @param list<array<string, mixed>> $searches
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed>|null $comparison
     * @param array<string, mixed> $ai
     *
     * @return array<string, mixed>
     */
    private function incomplete(
        array $module,
        array $source,
        ?array $review,
        array $searches,
        array $candidates,
        ?array $comparison,
        array $ai,
        string $stage,
    ): array {
        return [
            'complete' => false,
            'module' => $module,
            'source' => $source,
            'review' => $review,
            'searches' => $searches,
            'candidates' => $candidates,
            'comparison' => $comparison,
            'assessment' => null,
            'ai' => $ai,
            'limitations' => [
                [
                    'stage' => $stage,
                    'message' => self::LIMITATION_MESSAGES[$stage],
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     files: int,
     *     bytes: int,
     *     skipped: list<array{path: string, reason: string}>,
     *     redactions: list<array{path: string, line: int, type: string}>
     * }
     */
    private function emptySource(): array
    {
        return [
            'files' => 0,
            'bytes' => 0,
            'skipped' => [],
            'redactions' => [],
        ];
    }

    /**
     * @param array<string, mixed> $review
     *
     * @return list<array{type: 'name'|'core'|'functional'|'domain', term: string}>
     */
    private function buildSearchPlan(array $review, ?string $humanName): array
    {
        $plan = [];
        $seen = [];
        if (is_string($humanName) && trim($humanName) !== '') {
            $this->addSearchPlanItem($plan, $seen, 'name', $humanName);
        }
        foreach ($review['primary_search_terms'] as $term) {
            $this->addSearchPlanItem($plan, $seen, 'core', $term['term']);
        }
        foreach ($review['functional_search_queries'] as $query) {
            $this->addSearchPlanItem($plan, $seen, 'functional', $query['query']);
        }
        foreach ($review['secondary_search_terms'] as $term) {
            $this->addSearchPlanItem($plan, $seen, 'domain', $term['term']);
        }

        return $plan;
    }

    /**
     * @param list<array{type: 'name'|'core'|'functional'|'domain', term: string}> $plan
     * @param array<string, int> $seen
     * @param 'name'|'core'|'functional'|'domain' $type
     */
    private function addSearchPlanItem(array &$plan, array &$seen, string $type, string $term): void
    {
        $term = preg_replace('/\s+/u', ' ', trim($term)) ?? trim($term);
        if ($term === '') {
            return;
        }

        $key = strtolower($term);
        $priority = [
            'domain' => 0,
            'core' => 1,
            'functional' => 2,
            'name' => 3,
        ];
        if (isset($seen[$key])) {
            $index = $seen[$key];
            if ($priority[$type] > $priority[$plan[$index]['type']]) {
                $plan[$index]['type'] = $type;
            }
            return;
        }

        $seen[$key] = count($plan);
        $plan[] = [
            'type' => $type,
            'term' => $term,
        ];
    }

    /**
     * @param array{
     *     providerId: string,
     *     modelId: string,
     *     result: array<string, mixed>,
     *     tokenUsage: array{input: ?int, output: ?int, total: ?int}
     * } $response
     *
     * @return array{
     *     providerId: string,
     *     modelId: string,
     *     tokenUsage: array{input: ?int, output: ?int, total: ?int}
     * }
     */
    private function aiMetadata(array $response): array
    {
        return [
            'providerId' => $response['providerId'],
            'modelId' => $response['modelId'],
            'tokenUsage' => $response['tokenUsage'],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function emitProgress(?callable $progress, string $event, array $context = []): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }

    private function waitCallback(?callable $progress, string $event): ?callable
    {
        if ($progress === null) {
            return null;
        }

        return function () use ($progress, $event): void {
            $this->emitProgress($progress, $event);
        };
    }
}
