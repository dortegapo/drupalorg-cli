<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Project\DrupalAiBridge;
use mglaman\DrupalOrg\Project\DrupalOrgProjectSearch;
use mglaman\DrupalOrg\Project\DrupalOrgProjectCompatibilityResolver;
use mglaman\DrupalOrg\Project\ModuleContributionAssessmentResolver;
use mglaman\DrupalOrg\Project\ModuleReviewAnalyzer;
use mglaman\DrupalOrg\Project\ModuleReviewCandidateRanker;
use mglaman\DrupalOrg\Project\ModuleReviewComparisonPromptBuilder;
use mglaman\DrupalOrg\Project\ModuleReviewComparisonResultValidator;
use mglaman\DrupalOrg\Project\ModuleReviewCollector;
use mglaman\DrupalOrg\Project\ModuleReviewPromptBuilder;
use mglaman\DrupalOrg\Project\ModuleReviewResultValidator;
use mglaman\DrupalOrg\Project\ModuleReviewSensitiveDataSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(ModuleReviewAnalyzer::class)]
class ModuleReviewAnalyzerTest extends TestCase
{
    /**
     * @var array<int, array{request: RequestInterface}>
     */
    private array $history = [];

    private string $workDir;

    private string $modulePath;

    /**
     * @var array{
     *     type: 'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * }
     */
    private array $runtime;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/drupalorg-cli-analyzer-' . uniqid();
        $this->modulePath = $this->workDir . '/example_module';
        mkdir($this->modulePath, 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDir);
    }

    public function testRunsTheCompletePipelineWithSanitizedSourceAndAdvisoryAssessment(): void
    {
        $originalSource = "<?php\n\$api_key = 'ORIGINAL_SECRET';\n";
        file_put_contents($this->modulePath . '/module.php', $originalSource);

        $captureDir = $this->workDir . '/captures';
        mkdir($captureDir);
        $analyzer = $this->analyzer(
            [
                self::searchResponse([
                    self::project('profile_project', 'Profile project', 'Profile management.'),
                ]),
                self::searchResponse([
                    self::project('record_project', 'Record project', 'Record management.'),
                ]),
                self::searchResponse([
                    self::project('directory_project', 'Directory project', 'Directory management.'),
                ]),
                self::searchResponse([]),
            ],
            [
                self::aiSuccess(self::reviewResult()),
                self::aiSuccess(self::comparisonResult([
                    self::comparisonCandidate('profile_project', 'likely_duplicate', ['profiles']),
                ], 'likely_duplicate')),
            ],
            $captureDir,
        );

        $events = [];
        $result = $analyzer->analyze(
            $this->modulePath,
            'example_module',
            $this->runtime,
            static function (string $event, array $context) use (&$events): void {
                $events[] = [$event, $context];
            },
        );
        $result = $result->toArray();

        self::assertTrue($result['complete']);
        self::assertSame(
            ['machineName' => 'example_module', 'path' => $this->modulePath],
            $result['module'],
        );
        self::assertSame(1, $result['source']['files']);
        self::assertNotSame(strlen($originalSource), $result['source']['bytes']);
        $logicalEvents = array_values(array_filter(
            $events,
            static fn (array $event): bool => !str_ends_with($event[0], '_tick'),
        ));
        self::assertSame(
            [
                [
                    'path' => 'module.php',
                    'line' => 2,
                    'type' => 'credential',
                ],
            ],
            $result['source']['redactions'],
        );
        self::assertSame('Manages reusable profiles.', $result['review']['purpose']);
        self::assertSame(
            ['profile', 'records', 'profile management', 'directory'],
            array_column($result['searches'], 'term'),
        );
        self::assertCount(4, $this->history);
        foreach ($this->history as $index => $entry) {
            parse_str($entry['request']->getUri()->getQuery(), $query);
            self::assertSame(['profile', 'records', 'profile management', 'directory'][$index], $query['filter']['fulltext']);
            self::assertSame('20', $query['page']['limit']);
            self::assertSame('0', $query['page']['offset']);
        }
        self::assertCount(3, $result['candidates']);
        self::assertSame('likely_duplicate', $result['comparison']['verdict']);
        self::assertSame('not_candidate_yet', $result['assessment']['status']);
        self::assertArrayNotHasKey('publishAllowed', $result);
        self::assertArrayNotHasKey('blocked', $result);
        self::assertSame(
            [
                'providerId' => 'test_provider',
                'modelId' => 'test_model',
                'tokenUsage' => ['input' => 10, 'output' => 5, 'total' => 15],
            ],
            $result['ai']['review'],
        );
        self::assertSame($result['ai']['review'], $result['ai']['comparison']);
        self::assertSame(
            [
                'source_prepared',
                'review_ai_started',
                'review_ai_completed',
                'project_search_started',
                'project_search_progress',
                'project_search_progress',
                'project_search_progress',
                'project_search_progress',
                'project_search_completed',
                'candidate_ranking_completed',
                'comparison_ai_started',
                'comparison_ai_completed',
                'assessment_completed',
                'analysis_completed',
            ],
            array_column($logicalEvents, 0),
        );
        $searchProgressEvents = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event[0] === 'project_search_progress',
        ));
        self::assertSame(
            [
                'current' => 1,
                'total' => 4,
                'term' => 'profile',
                'type' => 'core',
            ],
            $searchProgressEvents[0][1],
        );
        $rankingEvent = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event[0] === 'candidate_ranking_completed',
        ))[0];
        self::assertSame(3, $rankingEvent[1]['candidateCount']);
        $comparisonStartEvent = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event[0] === 'comparison_ai_started',
        ))[0];
        self::assertSame(3, $comparisonStartEvent[1]['candidateCount']);

        $serializedResult = serialize($result);
        self::assertStringNotContainsString('ORIGINAL_SECRET', $serializedResult);
        self::assertStringNotContainsString('[REDACTED_SECRET]', $serializedResult);
        self::assertStringNotContainsString('systemPrompt', $serializedResult);
        self::assertStringNotContainsString('userPrompt', $serializedResult);

        $reviewPayload = self::capturedPayload($captureDir . '/call-0.php');
        self::assertStringNotContainsString('ORIGINAL_SECRET', $reviewPayload['user_prompt']);
        self::assertStringContainsString('[REDACTED_SECRET]', $reviewPayload['user_prompt']);

        $comparisonPayload = self::capturedPayload($captureDir . '/call-1.php');
        self::assertStringNotContainsString('score', $comparisonPayload['user_prompt']);
        self::assertStringNotContainsString('primaryMatches', $comparisonPayload['user_prompt']);
        self::assertStringNotContainsString('secondaryMatches', $comparisonPayload['user_prompt']);
        self::assertStringNotContainsString('functional_search_queries', $comparisonPayload['user_prompt']);
        self::assertStringNotContainsString('profile management', $comparisonPayload['user_prompt']);
    }

    public function testReturnsReviewAiFailureWithoutSearching(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $analyzer = $this->analyzer([], [self::aiFailure('provider_failure')]);

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime)->toArray();

        self::assertIncompleteAt('review_ai', $result);
        self::assertSame([], $result['searches']);
        self::assertSame([], $this->history);
        self::assertNull($result['comparison']);
        self::assertNull($result['assessment']);
    }

    public function testUsesHumanNameAndFunctionalQueriesAsDiscoverySignals(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $review = self::reviewResult();
        $review['primary_search_terms'] = [
            ['term' => 'navigation', 'reason' => 'Navigation capability.'],
            ['term' => 'shortcuts', 'reason' => 'Keyboard shortcut capability.'],
        ];
        $review['functional_search_queries'] = [
            ['query' => 'keyboard navigation', 'reason' => 'Keyboard navigation interaction.'],
            ['query' => 'command palette', 'reason' => 'Command palette interaction.'],
        ];
        $review['secondary_search_terms'] = [
            ['term' => 'toolbar', 'reason' => 'Toolbar integration.'],
        ];
        $captureDir = $this->workDir . '/captures';
        mkdir($captureDir);
        $analyzer = $this->analyzer([
            self::searchResponse([self::project('original_palette', 'Original Palette', 'Palette navigation.')]),
            self::searchResponse([]),
            self::searchResponse([]),
            self::searchResponse([self::project('original_palette', 'Original Palette', 'Palette navigation.')]),
            self::searchResponse([]),
            self::searchResponse([]),
        ], [
            self::aiSuccess($review),
            self::aiSuccess(self::comparisonResult([
                self::comparisonCandidate('original_palette', 'likely_duplicate', ['keyboard navigation']),
            ], 'likely_duplicate')),
        ]);

        $result = $analyzer->analyze(
            $this->modulePath,
            'renamed_palette_module',
            $this->runtime,
            null,
            'Original Palette',
        );
        $result = $result->toArray();

        self::assertTrue($result['complete']);
        self::assertSame(
            ['Original Palette', 'navigation', 'shortcuts', 'keyboard navigation', 'command palette', 'toolbar'],
            array_column($result['searches'], 'term'),
        );
        self::assertSame(
            ['name', 'core', 'core', 'functional', 'functional', 'domain'],
            array_column($result['searches'], 'type'),
        );
        self::assertSame('original_palette', $result['candidates'][0]['machineName']);
    }

    public function testFiltersIncompatibleCandidatesBeforeSemanticComparison(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $analyzer = $this->analyzer([
            self::searchResponse([self::project('profile_project', 'Profile project', 'Profile management.')]),
            self::searchResponse([]),
            self::searchResponse([]),
            self::searchResponse([]),
            self::compatibilityResponse([
                [
                    'version' => '1.0.0',
                    'require' => ['drupal/core' => '^10'],
                ],
            ]),
        ], [
            self::aiSuccess(self::reviewResult()),
        ], null, true);

        $events = [];
        $result = $analyzer->analyze(
            $this->modulePath,
            'example_module',
            $this->runtime,
            static function (string $event, array $context) use (&$events): void {
                $events[] = [$event, $context];
            },
            null,
            null,
            '^11',
        );
        $result = $result->toArray();

        self::assertTrue($result['complete']);
        self::assertSame([], $result['candidates']);
        self::assertSame('no_clear_duplicate', $result['comparison']['verdict']);
        self::assertStringContainsString('compatible with the module', $result['comparison']['summary']);
        self::assertCount(5, $this->history);
        self::assertSame(
            [
                'source_prepared',
                'review_ai_started',
                'review_ai_completed',
                'project_search_started',
                'project_search_progress',
                'project_search_progress',
                'project_search_progress',
                'project_search_progress',
                'project_search_completed',
                'candidate_ranking_completed',
                'compatibility_started',
                'compatibility_progress',
                'compatibility_completed',
                'assessment_completed',
                'analysis_completed',
            ],
            array_values(array_filter(
                array_column($events, 0),
                static fn (string $event): bool => !str_ends_with($event, '_tick'),
            )),
        );
    }

    public function testSendsCompatibleAndUnknownCandidatesToComparison(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $captureDir = $this->workDir . '/captures';
        mkdir($captureDir);
        $analyzer = $this->analyzer([
            self::searchResponse([
                self::project('compatible_project', 'Compatible', 'Profile management.'),
                self::project('unknown_project', 'Unknown', 'Profile management.'),
                self::project('incompatible_project', 'Incompatible', 'Profile management.'),
            ]),
            self::searchResponse([]),
            self::searchResponse([]),
            self::searchResponse([]),
            self::compatibilityResponse([
                self::package('1.0.0', '^11'),
            ]),
            self::compatibilityResponse([
                ['version' => '1.0.0'],
            ]),
            self::compatibilityResponse([
                self::package('1.0.0', '^10'),
            ]),
        ], [
            self::aiSuccess(self::reviewResult()),
            self::aiSuccess(self::comparisonResult([
                self::comparisonCandidate('compatible_project', 'likely_duplicate', ['profiles']),
                self::comparisonCandidate('unknown_project', 'possible_overlap', ['profiles']),
            ], 'likely_duplicate')),
        ], $captureDir, true);

        $result = $analyzer->analyze(
            $this->modulePath,
            'example_module',
            $this->runtime,
            null,
            null,
            null,
            '^11',
        )->toArray();

        self::assertSame(
            ['compatible_project', 'unknown_project'],
            array_column($result['candidates'], 'machineName'),
        );
        self::assertSame(1, $result['discovery']['candidatesExcludedByCore']);
        self::assertSame(1, $result['discovery']['candidatesWithUnknownCoreCompatibility']);
        self::assertSame('likely_duplicate', $result['comparison']['verdict']);
        self::assertSame('2', trim((string) file_get_contents($this->workDir . '/ai-count.txt')));
        $comparisonPayload = self::capturedPayload($captureDir . '/call-1.php');
        self::assertIsString($comparisonPayload['user_prompt'] ?? null);
        self::assertStringContainsString('compatible_project', $comparisonPayload['user_prompt']);
        self::assertStringContainsString('unknown_project', $comparisonPayload['user_prompt']);
        self::assertStringNotContainsString('incompatible_project', $comparisonPayload['user_prompt']);
    }

    public function testFunctionalSearchRecoversCandidateWhenHumanNameDoesNot(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $review = self::reviewResult();
        $review['primary_search_terms'] = [
            ['term' => 'navigation', 'reason' => 'Navigation capability.'],
            ['term' => 'shortcuts', 'reason' => 'Keyboard shortcut capability.'],
        ];
        $review['functional_search_queries'] = [
            ['query' => 'keyboard navigation', 'reason' => 'Keyboard navigation interaction.'],
        ];
        $review['secondary_search_terms'] = [
            ['term' => 'toolbar', 'reason' => 'Toolbar integration.'],
        ];
        $analyzer = $this->analyzer([
            self::searchResponse([]),
            self::searchResponse([]),
            self::searchResponse([]),
            self::searchResponse([self::project('original_palette', 'Original Palette', 'Palette navigation.')]),
            self::searchResponse([]),
        ], [
            self::aiSuccess($review),
            self::aiSuccess(self::comparisonResult([
                self::comparisonCandidate('original_palette', 'likely_duplicate', ['keyboard navigation']),
            ], 'likely_duplicate')),
        ]);

        $result = $analyzer->analyze(
            $this->modulePath,
            'renamed_palette_module',
            $this->runtime,
            null,
            'Admin Quickjump POC',
        );
        $result = $result->toArray();

        self::assertTrue($result['complete']);
        self::assertSame('original_palette', $result['candidates'][0]['machineName']);
    }

    public function testDeduplicatesHumanNameAndCoreTermCaseInsensitively(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $review = self::reviewResult();
        $review['primary_search_terms'][0]['term'] = 'reservations';
        $review['functional_search_queries'] = [
            ['query' => 'reservation management', 'reason' => 'Reservation interaction.'],
        ];
        $review['secondary_search_terms'] = [];
        $analyzer = $this->analyzer([
            self::searchResponse([]),
            self::searchResponse([]),
            self::searchResponse([]),
        ], [
            self::aiSuccess($review),
            self::aiSuccess(self::comparisonResult([], 'no_clear_duplicate')),
        ]);

        $result = $analyzer->analyze(
            $this->modulePath,
            'reservations_module',
            $this->runtime,
            null,
            'Reservations',
        );
        $result = $result->toArray();

        self::assertTrue($result['complete']);
        self::assertSame(['Reservations', 'records', 'reservation management'], array_column($result['searches'], 'term'));
        self::assertCount(3, $this->history);
    }

    public function testCapsTheSearchPlanAtTheTenTheoreticalSearches(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $review = self::reviewResult();
        $review['primary_search_terms'] = self::terms(['alpha', 'bravo', 'charlie', 'delta']);
        $review['functional_search_queries'] = [
            ['query' => 'alpha capability', 'reason' => 'First functional query.'],
            ['query' => 'bravo workflow', 'reason' => 'Second functional query.'],
        ];
        $review['secondary_search_terms'] = self::terms(['fitness', 'nutrition', 'wellness']);
        $responses = array_map(
            static fn (): Response => self::searchResponse([]),
            range(1, 10),
        );
        $analyzer = $this->analyzer($responses, [
            self::aiSuccess($review),
            self::aiSuccess(self::comparisonResult([], 'no_clear_duplicate')),
        ]);

        $result = $analyzer->analyze(
            $this->modulePath,
            'example_module',
            $this->runtime,
            null,
            'Example Module',
        );
        $result = $result->toArray();

        self::assertTrue($result['complete']);
        self::assertCount(10, $result['searches']);
        foreach ($this->history as $entry) {
            parse_str($entry['request']->getUri()->getQuery(), $query);
            self::assertSame('20', $query['page']['limit']);
            self::assertSame('0', $query['page']['offset']);
        }
    }

    public function testReportsMultiQueryDiscoveryMetadata(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $review = self::reviewResult();
        $review['functional_search_queries'] = [['query' => 'profile management', 'reason' => 'Functional search.']];
        $review['secondary_search_terms'] = [];
        $analyzer = $this->analyzer([
            self::searchResponse([
                self::project('candidate_shared', 'Shared', 'Shared.'),
                self::project('candidate_unique_a', 'A', 'A.'),
            ]),
            self::searchResponse([
                self::project('candidate_shared', 'Shared', 'Shared.'),
                self::project('candidate_unique_b', 'B', 'B.'),
            ]),
            self::searchResponse([]),
        ], [
            self::aiSuccess($review),
            self::aiSuccess(self::comparisonResult([], 'no_clear_duplicate')),
        ]);

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime)->toArray();

        self::assertSame([
            'searches' => 3,
            'rawResultOccurrences' => 4,
            'uniqueCandidatesDiscovered' => 3,
            'multiQueryCandidates' => 1,
            'candidatesSentToComparison' => 3,
            'candidatesRankedBeforeCoreFilter' => 3,
            'candidatesExcludedByCore' => 0,
            'candidatesWithUnknownCoreCompatibility' => 0,
        ], $result['discovery']);
    }

    public function testLimitsTheCandidatePoolSentToComparison(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $review = self::reviewResult();
        $review['primary_search_terms'] = self::terms(['alpha', 'bravo', 'charlie', 'delta']);
        $review['functional_search_queries'] = [
            ['query' => 'alpha capability', 'reason' => 'First query.'],
            ['query' => 'bravo workflow', 'reason' => 'Second query.'],
        ];
        $review['secondary_search_terms'] = self::terms(['fitness', 'nutrition', 'wellness']);

        $responses = [];
        for ($query = 0; $query < 10; ++$query) {
            $projects = [];
            for ($rank = 1; $rank <= 20; ++$rank) {
                $machineName = 'candidate_' . $query . '_' . $rank;
                $projects[] = self::project($machineName, $machineName, 'Description.');
            }
            $responses[] = self::searchResponse($projects);
        }
        for ($candidate = 0; $candidate < 40; ++$candidate) {
            $responses[] = self::compatibilityResponse([
                self::package('1.0.0', $candidate < 20 ? '^10' : '^11'),
            ]);
        }
        $captureDir = $this->workDir . '/captures';
        mkdir($captureDir);
        $analyzer = $this->analyzer(
            $responses,
            [
                self::aiSuccess($review),
                self::aiSuccess(self::comparisonResult([], 'no_clear_duplicate')),
            ],
            $captureDir,
            true,
        );

        $result = $analyzer->analyze(
            $this->modulePath,
            'example_module',
            $this->runtime,
            null,
            'Example Module',
            null,
            '^11',
        );
        $result = $result->toArray();

        self::assertTrue($result['complete']);
        self::assertSame(200, $result['discovery']['rawResultOccurrences']);
        self::assertSame(200, $result['discovery']['uniqueCandidatesDiscovered']);
        self::assertSame(40, $result['discovery']['candidatesRankedBeforeCoreFilter']);
        self::assertSame(20, $result['discovery']['candidatesExcludedByCore']);
        self::assertSame(20, $result['discovery']['candidatesSentToComparison']);
        self::assertCount(20, $result['candidates']);
        $comparisonPayload = self::capturedPayload($captureDir . '/call-1.php');
        $userPrompt = $comparisonPayload['user_prompt'];
        self::assertIsString($userPrompt);
        $inputJson = trim((string) substr($userPrompt, strlen("INPUT DATA START\n"), -strlen("\nINPUT DATA END")));
        $input = json_decode($inputJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(20, $input['candidates']);
    }

    public function testSkipsComparisonAiWhenNoCandidatesAreFound(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $captureDir = $this->workDir . '/captures';
        mkdir($captureDir);
        $analyzer = $this->analyzer(
            [
                self::searchResponse([]),
                self::searchResponse([]),
                self::searchResponse([]),
                self::searchResponse([]),
            ],
            [self::aiSuccess(self::reviewResult())],
            $captureDir,
        );

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime)->toArray();

        self::assertTrue($result['complete']);
        self::assertSame([], $result['candidates']);
        self::assertSame('no_clear_duplicate', $result['comparison']['verdict']);
        self::assertSame('candidate', $result['assessment']['status']);
        self::assertFileExists($captureDir . '/call-0.php');
        self::assertFileDoesNotExist($captureDir . '/call-1.php');
    }

    public function testContinuesToReviewAiWhenSourceExceedsTheHistoricalBuilderBudget(): void
    {
        file_put_contents($this->modulePath . '/module.php', str_repeat('a', 131073));
        $analyzer = $this->analyzer([], []);

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime)->toArray();

        self::assertIncompleteAt('review_ai', $result);
        self::assertSame([], $this->history);
        self::assertNull($result['review']);
    }

    public function testProviderContextFailureForFullSourceAboveLargestBudgetIsReviewAiFailure(): void
    {
        $content = str_repeat('a', 524289);
        $preparedSource = [
            'files' => [[
                'path' => 'large.php',
                'bytes' => strlen($content),
                'content' => $content,
            ]],
            'skipped' => [],
            'totalBytes' => strlen($content),
            'redactions' => [],
            'coverage' => [
                'mode' => 'full',
                'requestedBudgetBytes' => null,
                'eligibleFiles' => 1,
                'eligibleBytes' => strlen($content),
                'selectedFiles' => 1,
                'selectedBytes' => strlen($content),
                'omittedFiles' => 0,
                'omittedBytes' => 0,
                'omittedBreakdown' => [],
            ],
        ];
        $analyzer = $this->analyzer([], [self::aiFailure('context overflow')]);

        $result = $analyzer->analyze(
            $this->modulePath,
            'example_module',
            $this->runtime,
            null,
            null,
            $preparedSource,
        );
        $result = $result->toArray();

        self::assertIncompleteAt('review_ai', $result);
        self::assertSame('full', $result['source']['coverage']['mode']);
        self::assertSame(strlen($content), $result['source']['bytes']);
        self::assertStringNotContainsString('context overflow', (string) json_encode($result));
    }

    public function testStopsAfterAProjectSearchFailure(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $analyzer = $this->analyzer(
            [
                self::searchResponse([]),
                new ConnectException('search failure', new Request('GET', 'https://www.drupal.org')),
            ],
            [self::aiSuccess(self::reviewResult())],
        );

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime)->toArray();

        self::assertIncompleteAt('project_search', $result);
        self::assertCount(1, $result['searches']);
        self::assertCount(2, $this->history);
        self::assertNull($result['comparison']);
        self::assertNull($result['assessment']);
    }

    public function testStopsAfterComparisonAiFailure(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $analyzer = $this->analyzer(
            [
                self::searchResponse([self::project('profile_project', 'Profile project', 'Profile management.')]),
                self::searchResponse([]),
                self::searchResponse([]),
                self::searchResponse([]),
            ],
            [
                self::aiSuccess(self::reviewResult()),
                self::aiFailure('provider_failure'),
            ],
        );

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime)->toArray();

        self::assertIncompleteAt('comparison_ai', $result);
        self::assertNotNull($result['review']);
        self::assertNull($result['comparison']);
        self::assertNull($result['assessment']);
    }

    public function testStopsAfterReviewValidationFailure(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $analyzer = $this->analyzer([], [self::aiSuccess(['unexpected' => 'result'])]);

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime)->toArray();

        self::assertIncompleteAt('review_validation', $result);
        self::assertSame([], $result['searches']);
    }

    public function testStopsAfterComparisonValidationFailure(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $analyzer = $this->analyzer(
            [
                self::searchResponse([self::project('profile_project', 'Profile project', 'Profile management.')]),
                self::searchResponse([]),
                self::searchResponse([]),
                self::searchResponse([]),
            ],
            [
                self::aiSuccess(self::reviewResult()),
                self::aiSuccess(self::comparisonResult([], 'likely_duplicate')),
            ],
        );

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime)->toArray();

        self::assertIncompleteAt('comparison_validation', $result);
        self::assertNull($result['comparison']);
        self::assertNull($result['assessment']);
    }

    public function testCompletesWithZeroCandidates(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $analyzer = $this->analyzer(
            [self::searchResponse([]), self::searchResponse([]), self::searchResponse([]), self::searchResponse([])],
            [
                self::aiSuccess(self::reviewResult()),
                self::aiSuccess(self::comparisonResult([], 'no_clear_duplicate')),
            ],
        );

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime)->toArray();

        self::assertTrue($result['complete']);
        self::assertSame([], $result['candidates']);
        self::assertSame([], $result['comparison']['candidates']);
        self::assertSame('candidate', $result['assessment']['status']);
        self::assertSame([], $result['limitations']);
    }

    public function testReturnsCollectionFailureForAnInvalidModulePath(): void
    {
        $analyzer = $this->analyzer([], []);

        $result = $analyzer->analyze($this->workDir . '/missing', 'example_module', $this->runtime)->toArray();

        self::assertIncompleteAt('collection', $result);
        self::assertSame([], $result['source']['skipped']);
    }

    public function testReturnsSourceEmptyWithoutCallingAiOrDrupalOrg(): void
    {
        file_put_contents($this->modulePath . '/logo.png', 'binary-looking content');
        $analyzer = $this->analyzer([], []);

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime)->toArray();

        self::assertIncompleteAt('source_empty', $result);
        self::assertNull($result['review']);
        self::assertSame([], $result['searches']);
        self::assertSame([], $result['candidates']);
        self::assertNull($result['assessment']);
        self::assertFileDoesNotExist($this->workDir . '/ai-count.txt');
        self::assertSame(
            [['path' => 'logo.png', 'reason' => 'unsupported']],
            $result['source']['skipped'],
        );
    }

    /**
     * @param list<Response|\Throwable> $searchResponses
     * @param list<string> $aiOutputs
     */
    private function analyzer(
        array $searchResponses,
        array $aiOutputs,
        ?string $captureDir = null,
        bool $withCompatibility = false,
    ): ModuleReviewAnalyzer {
        $this->history = [];
        $responses = new MockHandler($searchResponses);
        $stack = HandlerStack::create($responses);
        $stack->push(Middleware::history($this->history));

        $this->runtime = $this->createRuntime($aiOutputs, $captureDir);
        $client = new Client(false, $stack);

        return new ModuleReviewAnalyzer(
            new ModuleReviewCollector(),
            new ModuleReviewSensitiveDataSanitizer(),
            new ModuleReviewPromptBuilder(),
            new ModuleReviewResultValidator(),
            new DrupalAiBridge(),
            new DrupalOrgProjectSearch($client),
            new ModuleReviewCandidateRanker(),
            new ModuleReviewComparisonPromptBuilder(),
            new ModuleReviewComparisonResultValidator(),
            new ModuleContributionAssessmentResolver(),
            $withCompatibility ? new DrupalOrgProjectCompatibilityResolver($client) : null,
        );
    }

    /**
     * @param list<string> $outputs
     *
     * @return array{
     *     type: 'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * }
     */
    private function createRuntime(array $outputs, ?string $captureDir): array
    {
        $countFile = $this->workDir . '/ai-count.txt';
        $responseFiles = [];
        foreach ($outputs as $index => $output) {
            $responseFile = $this->workDir . '/ai-response-' . $index . '.txt';
            file_put_contents($responseFile, $output);
            $responseFiles[] = $responseFile;
        }

        $fakeExecutable = $this->workDir . '/fake-drush.php';
        file_put_contents(
            $fakeExecutable,
            <<<'PHP'
<?php
$countFile = $argv[1];
$captureDir = $argv[2];
$responseFiles = array_slice($argv, 3);
if (in_array('php:eval', $argv, true)) {
    fwrite(STDOUT, "__DRUPALORG_CLI_CORE_VERSION__=11.4.8\n");
    exit(0);
}
$index = is_file($countFile) ? (int) file_get_contents($countFile) : 0;
file_put_contents($countFile, (string) ($index + 1));
$input = stream_get_contents(STDIN);
if ($captureDir !== '') {
    file_put_contents($captureDir . '/call-' . $index . '.php', $input);
}
$responseFile = $responseFiles[$index] ?? null;
if ($responseFile !== null) {
    fwrite(STDOUT, (string) file_get_contents($responseFile));
}
PHP
        );

        return [
            'type' => 'local-drush',
            'workingDirectory' => $this->workDir,
            'command' => array_merge(
                [PHP_BINARY, $fakeExecutable, $countFile, $captureDir ?? ''],
                $responseFiles,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function aiSuccess(array $result): string
    {
        return self::structuredOutput([
            'ok' => true,
            'provider_id' => 'test_provider',
            'model_id' => 'test_model',
            'result' => $result,
            'token_usage' => [
                'input' => 10,
                'output' => 5,
                'total' => 15,
            ],
        ]);
    }

    private static function aiFailure(string $error): string
    {
        return self::structuredOutput([
            'ok' => false,
            'error' => $error,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function reviewResult(): array
    {
        return [
            'purpose' => 'Manages reusable profiles.',
            'capabilities' => ['Creates and manages profiles.'],
            'drupal_concepts' => ['content entities'],
            'integrations' => [],
            'primary_search_terms' => [
                ['term' => 'profile', 'reason' => 'Profile management.'],
                ['term' => 'records', 'reason' => 'Record management.'],
            ],
            'secondary_search_terms' => [
                ['term' => 'directory', 'reason' => 'Directory specialization.'],
            ],
            'functional_search_queries' => [
                ['query' => 'profile management', 'reason' => 'Profile management interaction.'],
            ],
            'reusability' => [
                'status' => 'reusable',
                'confidence' => 0.9,
                'findings' => [],
            ],
            'contrib_readiness' => [
                'status' => 'ready',
                'required_changes' => [],
                'advisory_notes' => [],
            ],
            'analysis_limits' => [],
        ];
    }

    /**
     * @param list<string> $terms
     *
     * @return list<array{term: string, reason: string}>
     */
    private static function terms(array $terms): array
    {
        return array_map(
            static fn (string $term): array => [
                'term' => $term,
                'reason' => 'Supported by the supplied source.',
            ],
            $terms,
        );
    }

    /**
     * @param list<array<string, mixed>> $candidates
     *
     * @return array<string, mixed>
     */
    private static function comparisonResult(array $candidates, string $verdict): array
    {
        return [
            'verdict' => $verdict,
            'confidence' => 0.8,
            'summary' => 'Comparison completed.',
            'candidates' => $candidates,
        ];
    }

    /**
     * @param list<string> $overlap
     *
     * @return array<string, mixed>
     */
    private static function comparisonCandidate(
        string $machineName,
        string $assessment,
        array $overlap,
    ): array {
        return [
            'machine_name' => $machineName,
            'assessment' => $assessment,
            'overlap' => $overlap,
            'confirmed_differences' => ['Different implementation details.'],
            'unverified_details' => [],
            'reason' => 'The candidate addresses the same reusable purpose.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function project(string $machineName, string $title, string $description): array
    {
        return [
            'attributes' => [
                'machine_name' => $machineName,
                'field_project_machine_name' => $machineName,
                'title' => $title,
                'body' => ['summary' => $description],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $projects
     */
    private static function searchResponse(array $projects): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/vnd.api+json'],
            json_encode(['data' => $projects], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private static function compatibilityResponse(array $packages): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['packages' => ['drupal/profile_project' => $packages]], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{version: string, require: array{'drupal/core': string}}
     */
    private static function package(string $version, string $constraint): array
    {
        return [
            'version' => $version,
            'require' => ['drupal/core' => $constraint],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function structuredOutput(array $payload): string
    {
        return '__DRUPALORG_CLI_AI_RESULT__=' . json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function capturedPayload(string $path): array
    {
        $script = file_get_contents($path);
        self::assertIsString($script);
        $matches = [];
        self::assertSame(1, preg_match("/base64_decode\\('([A-Za-z0-9+\\/=]+)'/", $script, $matches));
        self::assertArrayHasKey(1, $matches);
        self::assertIsString($matches[1]);
        $decoded = base64_decode($matches[1], true);
        self::assertIsString($decoded);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /** @param array<string, mixed> $result */
    private static function assertIncompleteAt(string $stage, array $result): void
    {
        self::assertFalse($result['complete']);
        self::assertSame($stage, $result['limitations'][0]['stage']);
        self::assertNotSame('', $result['limitations'][0]['message']);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
