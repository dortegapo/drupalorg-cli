<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use GuzzleHttp\Promise\Utils;

final class ModuleReviewAnalyzer
{
    private const SEARCH_LIMIT = 20;

    /** Keep a broad ranked pool so compatibility filtering cannot hide later matches. */
    private const PREFILTER_CANDIDATES = 40;

    private const MAXIMUM_COMPARISON_CANDIDATES = 20;

    private const SEARCH_CONCURRENCY = 4;

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
        private readonly ?DrupalOrgProjectCompatibilityResolver $compatibilityResolver = null,
    ) {
    }

    /**
     * Collect and sanitize all eligible module source files.
     *
     * The caller may subsequently replace the files and coverage metadata with
     * a relevance-selected subset before passing the result to analyze().
     *
     * @return PreparedModuleSource
     */
    public function prepareSource(string $modulePath): PreparedModuleSource
    {
        $collection = $this->collector->collect($modulePath);

        return $this->prepareSourceFromCollection($collection);
    }

    /**
     * @param array{
     *     files: list<array{path: string, bytes: int, content: string}>,
     *     skipped: list<array{path: string, reason: string}>
     * } $collection
     *
     * @return PreparedModuleSource
     */
    private function prepareSourceFromCollection(array $collection): PreparedModuleSource
    {
        $sanitized = $this->sanitizer->sanitize($collection['files']);
        $selection = (new ModuleReviewSourceSelector())->select($sanitized['files']);

        return new PreparedModuleSource(
            files: $selection['files'],
            skipped: $collection['skipped'],
            totalBytes: $selection['selectedBytes'],
            redactions: $sanitized['redactions'],
            coverage: SourceCoverage::fromArray($selection['coverage']),
        );
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
     * @param PreparedModuleSource|array<string, mixed>|null $preparedSource
     * @param string|null $coreVersionRequirement Source of truth from .info.yml.
     *
     * @return ModuleReviewResult
     */
    public function analyze(
        string $modulePath,
        string $machineName,
        array $runtime,
        ?callable $progress = null,
        ?string $humanName = null,
        PreparedModuleSource|array|null $preparedSource = null,
        ?string $coreVersionRequirement = null,
    ): ModuleReviewResult {
        $module = [
            'machineName' => $machineName,
            'path' => $modulePath,
        ];
        $source = $this->emptySource();
        $ai = [
            'review' => null,
            'comparison' => null,
        ];

        if (is_array($preparedSource)) {
            $preparedSource = PreparedModuleSource::fromArray($preparedSource);
        }

        if ($preparedSource === null) {
            try {
                $collection = $this->collector->collect($modulePath);
            } catch (\RuntimeException) {
                return $this->incomplete($module, $source, null, [], [], null, $ai, 'collection');
            }
            try {
                $preparedSource = $this->prepareSourceFromCollection($collection);
            } catch (\RuntimeException) {
                return $this->incomplete($module, $source, null, [], [], null, $ai, 'sanitization');
            }
        }

        $sanitizedCollection = [
            'files' => $preparedSource->files,
            'skipped' => $preparedSource->skipped,
            'totalBytes' => $preparedSource->totalBytes,
            'coverage' => $preparedSource->coverage->toArray(),
        ];
        $source['files'] = count($sanitizedCollection['files']);
        $source['bytes'] = $sanitizedCollection['totalBytes'];
        $source['skipped'] = $sanitizedCollection['skipped'];
        $source['redactions'] = $preparedSource->redactions;
        $source['coverage'] = $sanitizedCollection['coverage'];
        $this->emitProgress($progress, 'source_prepared', [
            'files' => count($sanitizedCollection['files']),
            'bytes' => $source['bytes'],
            'redactions' => count($source['redactions']),
            'eligibleFiles' => $source['coverage']['eligibleFiles'],
            'eligibleBytes' => $source['coverage']['eligibleBytes'],
            'selectedFiles' => $source['coverage']['selectedFiles'],
            'selectedBytes' => $source['coverage']['selectedBytes'],
            'omittedFiles' => $source['coverage']['omittedFiles'],
        ]);

        if ($sanitizedCollection['files'] === []) {
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
            $review = $this->reviewValidator->validate($reviewAi['result'], $sanitizedCollection['files'], $machineName);
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
        foreach (array_chunk($searchPlan, self::SEARCH_CONCURRENCY) as $searchBatch) {
            $promises = [];
            foreach ($searchBatch as $searchItem) {
                $promises[] = $this->projectSearch->searchAsync($searchItem['term'], self::SEARCH_LIMIT, 0);
            }
            try {
                $settled = Utils::settle($promises)->wait();
            } catch (\Throwable) {
                $failedItem = $searchBatch[0];
                $this->emitProgress($progress, 'project_search_failed', [
                    'current' => $searchCurrent + 1,
                    'total' => $searchTotal,
                    'term' => $failedItem['term'],
                    'type' => $failedItem['type'],
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

            foreach ($settled as $index => $result) {
                $searchItem = $searchBatch[$index];
                $term = $searchItem['term'];
                $type = $searchItem['type'];
                if ($result['state'] !== 'fulfilled') {
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

                /** @var list<array{machineName: string, title: string, description: string, url: string}> $projects */
                $projects = $result['value'];
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
        }
        $this->emitProgress($progress, 'project_search_completed', [
            'total' => $searchTotal,
        ]);

        try {
            $prefilterCandidates = $this->candidateRanker->rank(
                $primaryRankings,
                $secondaryRankings,
                self::PREFILTER_CANDIDATES,
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
            'candidateCount' => count($prefilterCandidates),
        ]);

        $compatibilityLimitations = [];
        $excludedByCore = 0;
        $unknownCoreCompatibility = 0;
        $candidates = $prefilterCandidates;
        if ($this->compatibilityResolver !== null && $coreVersionRequirement !== null) {
            try {
                $localCoreMajors = DrupalCoreVersionRequirement::supportedMajors($coreVersionRequirement);
                $compatibilityTotal = count($prefilterCandidates);
                $this->emitProgress($progress, 'compatibility_started', [
                    'current' => 0,
                    'total' => $compatibilityTotal,
                ]);
                $compatibilities = $this->compatibilityResolver->resolveMany(
                    array_values(array_map(
                        static fn (array $candidate): string => $candidate['machineName'],
                        $prefilterCandidates,
                    )),
                    $localCoreMajors,
                );
                $candidates = [];
                foreach ($prefilterCandidates as $index => $candidate) {
                    $compatibility = $compatibilities[$candidate['machineName']]
                        ?? $this->compatibilityResolver->unknown();
                    $status = $compatibility['status'];
                    if ($status === 'compatible' || $status === 'unknown') {
                        $candidates[] = $candidate;
                    } else {
                        ++$excludedByCore;
                    }
                    if ($status === 'unknown') {
                        ++$unknownCoreCompatibility;
                    }
                    $this->emitProgress($progress, 'compatibility_progress', [
                        'current' => $index + 1,
                        'total' => $compatibilityTotal,
                        'machineName' => $candidate['machineName'],
                    ]);
                }
                $this->emitProgress($progress, 'compatibility_completed', [
                    'current' => $compatibilityTotal,
                    'total' => $compatibilityTotal,
                ]);
                if ($unknownCoreCompatibility > 0) {
                    $compatibilityLimitations[] = [
                        'stage' => 'compatibility',
                        'message' => self::LIMITATION_MESSAGES['compatibility'],
                    ];
                }
            } catch (\InvalidArgumentException) {
                // An invalid module requirement must not discard possible matches.
                // Keep the broad pool and record the limitation for the report.
                $unknownCoreCompatibility = count($prefilterCandidates);
                $compatibilityLimitations[] = [
                    'stage' => 'compatibility',
                    'message' => self::LIMITATION_MESSAGES['compatibility'],
                ];
            }
        }
        $candidates = array_slice($candidates, 0, self::MAXIMUM_COMPARISON_CANDIDATES);
        $discovery = $this->discoveryMetadata(
            $searches,
            $candidates,
            count($prefilterCandidates),
            $excludedByCore,
            $unknownCoreCompatibility,
        );

        if ($candidates === []) {
            $comparison = [
                'verdict' => 'no_clear_duplicate',
                'confidence' => 1.0,
                'summary' => 'No functionally relevant Drupal.org projects compatible with the module\'s supported Drupal core versions were found.',
                'candidates' => [],
            ];
            $assessment = $this->assessmentResolver->resolve($review, $comparison);

            return $this->completeWithoutCandidates(
                $module,
                $source,
                $review,
                $searches,
                $comparison,
                $assessment,
                $ai,
                $progress,
                $discovery,
                $compatibilityLimitations,
            );
        }

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
            $comparison = $this->comparisonValidator->validate($comparisonAi['result'], $candidates)->toArray();
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

        $result = [
            'complete' => true,
            'module' => $module,
            'source' => $source,
            'review' => $review,
            'searches' => $searches,
            'candidates' => $candidates,
            'discovery' => $discovery,
            'comparison' => $comparison,
            'assessment' => $assessment,
            'ai' => $ai,
            'limitations' => $compatibilityLimitations,
        ];
        $this->emitProgress($progress, 'analysis_completed', [
            'complete' => true,
        ]);

        return ModuleReviewResult::fromArray($result);
    }

    /**
     * @param array<string, mixed> $module
     * @param array<string, mixed> $source
     * @param array<string, mixed> $review
     * @param list<array<string, mixed>> $searches
     * @param array<string, mixed> $comparison
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $ai
     * @param array<string, mixed> $discovery
     * @param list<array{stage: string, message: string}> $limitations
     *
     * @return ModuleReviewResult
     */
    private function completeWithoutCandidates(
        array $module,
        array $source,
        array $review,
        array $searches,
        array $comparison,
        array $assessment,
        array $ai,
        ?callable $progress,
        array $discovery,
        array $limitations = [],
    ): ModuleReviewResult {
        $result = [
            'complete' => true,
            'module' => $module,
            'source' => $source,
            'review' => $review,
            'searches' => $searches,
            'candidates' => [],
            'discovery' => $discovery,
            'comparison' => $comparison,
            'assessment' => $assessment,
            'ai' => $ai,
            'limitations' => $limitations,
        ];
        $this->emitProgress($progress, 'assessment_completed', [
            'status' => $assessment['status'],
        ]);
        $this->emitProgress($progress, 'analysis_completed', [
            'complete' => true,
        ]);

        return ModuleReviewResult::fromArray($result);
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
     * @return ModuleReviewResult
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
    ): ModuleReviewResult {
        return ModuleReviewResult::fromArray([
            'complete' => false,
            'module' => $module,
            'source' => $source,
            'review' => $review,
            'searches' => $searches,
            'candidates' => $candidates,
            'discovery' => $this->discoveryMetadata($searches, $candidates),
            'comparison' => $comparison,
            'assessment' => null,
            'ai' => $ai,
            'limitations' => [
                [
                    'stage' => $stage,
                    'message' => self::LIMITATION_MESSAGES[$stage],
                ],
            ],
        ]);
    }

    /**
     * @return array{
     *     files: int,
     *     bytes: int,
     *     skipped: list<array{path: string, reason: string}>,
     *     redactions: list<array{path: string, line: int, type: string}>,
     *     coverage: array<string, mixed>
     * }
     */
    private function emptySource(): array
    {
        return [
            'files' => 0,
            'bytes' => 0,
            'skipped' => [],
            'redactions' => [],
            'coverage' => [
                'mode' => 'full',
                'requestedBudgetBytes' => null,
                'eligibleFiles' => 0,
                'eligibleBytes' => 0,
                'selectedFiles' => 0,
                'selectedBytes' => 0,
                'omittedFiles' => 0,
                'omittedBytes' => 0,
                'omittedBreakdown' => [],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $searches
     * @param list<array<string, mixed>> $candidates
     *
     * @return array{
     *     searches: int,
     *     rawResultOccurrences: int,
     *     uniqueCandidatesDiscovered: int,
     *     multiQueryCandidates: int,
     *     candidatesSentToComparison: int,
     *     candidatesRankedBeforeCoreFilter: int,
     *     candidatesExcludedByCore: int,
     *     candidatesWithUnknownCoreCompatibility: int
     * }
     */
    private function discoveryMetadata(
        array $searches,
        array $candidates,
        ?int $rankedBeforeCoreFilter = null,
        int $excludedByCore = 0,
        int $unknownCoreCompatibility = 0,
    ): array {
        $rawResultOccurrences = 0;
        $queriesByCandidate = [];

        foreach ($searches as $searchIndex => $search) {
            $projects = $search['results'] ?? [];
            if (!is_array($projects)) {
                continue;
            }

            $rawResultOccurrences += count($projects);
            $query = is_string($search['term'] ?? null)
                ? strtolower(trim($search['term']))
                : 'search-' . $searchIndex;
            foreach ($projects as $project) {
                if (!is_array($project) || !is_string($project['machineName'] ?? null)) {
                    continue;
                }

                $machineName = trim($project['machineName']);
                if ($machineName === '') {
                    continue;
                }
                $queriesByCandidate[$machineName][$query] = true;
            }
        }

        $multiQueryCandidates = 0;
        foreach ($queriesByCandidate as $queries) {
            if (count($queries) > 1) {
                ++$multiQueryCandidates;
            }
        }

        return [
            'searches' => count($searches),
            'rawResultOccurrences' => $rawResultOccurrences,
            'uniqueCandidatesDiscovered' => count($queriesByCandidate),
            'multiQueryCandidates' => $multiQueryCandidates,
            'candidatesSentToComparison' => count($candidates),
            'candidatesRankedBeforeCoreFilter' => $rankedBeforeCoreFilter ?? count($candidates),
            'candidatesExcludedByCore' => $excludedByCore,
            'candidatesWithUnknownCoreCompatibility' => $unknownCoreCompatibility,
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
