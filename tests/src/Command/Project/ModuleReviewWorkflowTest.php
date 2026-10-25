<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ModuleInfo;
use mglaman\DrupalOrg\Project\PreparedModuleSource;
use mglaman\DrupalOrg\Project\ResolvedModule;
use mglaman\DrupalOrgCli\Command\Project\ModuleReviewWorkflow;
use mglaman\DrupalOrgCli\Command\Project\ModuleReviewWorkflowResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(ModuleReviewWorkflow::class)]
final class ModuleReviewWorkflowTest extends TestCase
{
    public function testUnavailableRuntimeSkipsOptionalReview(): void
    {
        [$result, $state, $display] = $this->runWorkflow(null, self::availableAiContext(), [], "");

        self::assertTrue($result->continuePublishing);
        self::assertNull($result->runtime);
        self::assertNull($result->analysisResult);
        self::assertSame(1, $state->runtimeCalls);
        self::assertStringContainsString('not available', $display);
    }

    public function testUnavailableAiSkipsOptionalReview(): void
    {
        [$result, $state, $display] = $this->runWorkflow(
            self::runtime(),
            ['aiEnabled' => false, 'providerId' => null, 'modelId' => null],
            [],
            "",
        );

        self::assertTrue($result->continuePublishing);
        self::assertNull($result->analysisResult);
        self::assertSame(1, $state->inspectCalls);
        self::assertStringContainsString('not available', $display);
    }

    public function testAiInspectionFailureSkipsOptionalReview(): void
    {
        [$result, $state, $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            [],
            "",
            new \RuntimeException('Inspection failed.'),
        );

        self::assertTrue($result->continuePublishing);
        self::assertNull($result->analysisResult);
        self::assertSame(1, $state->inspectCalls);
        self::assertStringContainsString('not available', $display);
    }

    public function testRejectingReviewDoesNotRunAnalyzer(): void
    {
        [$result, $state, $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
            "n\n",
        );

        self::assertTrue($result->continuePublishing);
        self::assertNull($result->analysisResult);
        self::assertSame(0, $state->analyzeCalls);
        self::assertStringContainsString('Run AI-assisted contribution review?', $display);
    }

    public function testAcceptedReviewRunsOnceAndCanContinue(): void
    {
        [$result, $state, $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
            "y\ny\n",
        );

        self::assertTrue($result->continuePublishing);
        self::assertInstanceOf(ModuleReviewWorkflowResult::class, $result);
        self::assertSame(1, $state->analyzeCalls);
        self::assertSame(1, $state->pdfCalls);
        self::assertStringContainsString('Assessment           CANDIDATE WITH CHANGES', $display);
        self::assertStringContainsString('Full report:', $display);
    }

    public function testCandidateAssessmentDefaultsToContinuePublishing(): void
    {
        [$result, $state, $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult('candidate'),
            "y\n\n",
        );

        self::assertTrue($result->continuePublishing);
        self::assertSame(1, $state->analyzeCalls);
        self::assertStringContainsString('Continue with project publishing? [Y/n]', $display);
    }

    public function testNotCandidateAssessmentStillAllowsPublishingDecision(): void
    {
        [$result, , $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult('not_candidate_yet'),
            "y\ny\n",
        );

        self::assertTrue($result->continuePublishing);
        self::assertStringContainsString('Assessment           NOT A CANDIDATE YET', $display);
        self::assertStringContainsString('Continue with project publishing? [y/N]', $display);
    }

    public function testReviewCancellationIsRepresentedInTheResult(): void
    {
        [$result, $state] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
            "y\nn\n",
        );

        self::assertFalse($result->continuePublishing);
        self::assertNotNull($result->runtime);
        self::assertNotNull($result->analysisResult);
        self::assertSame(1, $state->analyzeCalls);
    }

    public function testSourceBudgetSelectionUsesRecommendedBudget(): void
    {
        [$result, $state, $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
            "y\n1\ny\n",
            null,
            null,
            self::sourceFiles(180 * 1024),
        );

        self::assertTrue($result->continuePublishing);
        self::assertSame(1, $state->analyzeCalls);
        self::assertNotNull($state->analyzePreparedSource);
        self::assertSame('selected', $state->analyzePreparedSource['coverage']['mode']);
        self::assertLessThanOrEqual(128 * 1024, $state->analyzePreparedSource['totalBytes']);
        self::assertStringContainsString('128 KiB  Recommended · lowest token usage', $display);
        self::assertStringContainsString('Cancel AI review', $display);
    }

    public function testSourceWithinRecommendedBudgetUsesFullSource(): void
    {
        [$result, $state, $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
            "y\ny\n",
            null,
            null,
            self::sourceFiles(80 * 1024),
        );

        self::assertTrue($result->continuePublishing);
        self::assertSame('full', $state->analyzePreparedSource['coverage']['mode']);
        self::assertStringContainsString('✓ Preparing module source', $display);
        self::assertStringNotContainsString('Choose how much source', $display);
    }

    public function testSourceBudgetCanSelectLargerAvailableBudget(): void
    {
        [$result, $state, $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
            "y\n2\ny\n",
            null,
            null,
            self::sourceFiles(390 * 1024),
        );

        self::assertTrue($result->continuePublishing);
        self::assertSame(256 * 1024, $state->analyzePreparedSource['coverage']['requestedBudgetBytes']);
        self::assertStringContainsString('  2. 256 KiB', $display);
    }

    public function testFullSourceCanBeSelectedAboveLargestRecommendedBudget(): void
    {
        [$result, $state] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
            "y\n4\ny\n",
            null,
            null,
            self::sourceFiles(900 * 1024),
        );

        self::assertTrue($result->continuePublishing);
        self::assertSame('full', $state->analyzePreparedSource['coverage']['mode']);
        self::assertGreaterThan(512 * 1024, $state->analyzePreparedSource['totalBytes']);
    }

    public function testSourceBudgetSelectionCanBeCancelled(): void
    {
        [$result, $state, $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
            "y\n3\n",
            null,
            null,
            self::sourceFiles(180 * 1024),
        );

        self::assertTrue($result->continuePublishing);
        self::assertSame(0, $state->analyzeCalls);
        self::assertStringContainsString('AI-assisted contribution review skipped.', $display);
    }

    public function testIncompleteReviewStillAllowsPublishingDecision(): void
    {
        [$result, $state, $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            [
                'complete' => false,
                'source' => ['redactions' => []],
                'limitations' => [['stage' => 'project_search', 'message' => 'Search unavailable.']],
            ],
            "y\nn\n",
        );

        self::assertFalse($result->continuePublishing);
        self::assertSame(1, $state->analyzeCalls);
        self::assertStringContainsString('AI module review could not be completed.', $display);
        self::assertStringNotContainsString('Assessment:', $display);
    }

    public function testPdfFailureDoesNotInvalidateReview(): void
    {
        [$result, $state, $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
            "y\nn\n",
            null,
            new \RuntimeException('PDF failed.'),
        );

        self::assertFalse($result->continuePublishing);
        self::assertSame(1, $state->pdfCalls);
        self::assertStringContainsString('PDF report could not be generated.', $display);
        self::assertStringContainsString('Assessment           CANDIDATE WITH CHANGES', $display);
    }

    public function testPartialTokenUsageIsRenderedAsUnavailable(): void
    {
        $review = self::completeReviewResult();
        $review['ai']['review']['tokenUsage'] = ['input' => 100, 'output' => null, 'total' => null];
        $review['ai']['comparison']['tokenUsage'] = ['input' => 200, 'output' => null, 'total' => null];

        [, , $display] = $this->runWorkflow(
            self::runtime(),
            self::availableAiContext(),
            $review,
            "y\nn\n",
        );

        self::assertStringNotContainsString('Tokens:', $display);
    }

    /**
     * @param array<string, mixed>|null $runtime
     * @param array{aiEnabled: bool, providerId: ?string, modelId: ?string} $aiContext
     * @param array<string, mixed> $analysisResult
     * @param \RuntimeException|null $inspectFailure
     * @param \RuntimeException|null $pdfFailure
     * @param list<array{path: string, bytes: int, content: string}>|null $sourceFiles
     * @return array{0: ModuleReviewWorkflowResult, 1: object, 2: string}
     */
    private function runWorkflow(
        ?array $runtime,
        array $aiContext,
        array $analysisResult,
        string $inputContents,
        ?\RuntimeException $inspectFailure = null,
        ?\RuntimeException $pdfFailure = null,
        ?array $sourceFiles = null,
    ): array {
        $state = new class {
            public int $runtimeCalls = 0;
            public int $inspectCalls = 0;
            public int $analyzeCalls = 0;
            public int $pdfCalls = 0;
            /** @var array<string, mixed>|null */
            public PreparedModuleSource|array|null $analyzePreparedSource = null;
        };
        $sourceFiles ??= self::sourceFiles(20);
        $workflow = new ModuleReviewWorkflow(
            new Client(),
            static function (?string $projectRoot) use ($state, $runtime): ?array {
                ++$state->runtimeCalls;
                return $runtime;
            },
            static function (array $resolvedRuntime) use ($state, $aiContext, $inspectFailure): array {
                ++$state->inspectCalls;
                if ($inspectFailure !== null) {
                    throw $inspectFailure;
                }

                return $aiContext;
            },
            static function (string $modulePath) use ($sourceFiles): array {
                return [
                    'files' => $sourceFiles,
                    'skipped' => [],
                    'totalBytes' => array_sum(array_map(
                        static fn (array $file): int => $file['bytes'],
                        $sourceFiles,
                    )),
                    'redactions' => [],
                ];
            },
            static function (
                string $modulePath,
                string $machineName,
                array $resolvedRuntime,
                ?callable $progress,
                ?string $humanName,
                PreparedModuleSource|array|null $preparedSource,
            ) use (
                $state,
                $analysisResult,
            ): array {
                ++$state->analyzeCalls;
                $state->analyzePreparedSource = $preparedSource;
                if ($progress !== null && ($analysisResult['complete'] ?? false) === true) {
                    $progress('review_ai_started', []);
                    $progress('review_ai_completed', []);
                    $progress('project_search_started', ['total' => 1]);
                    $progress('project_search_progress', [
                        'current' => 1,
                        'total' => 1,
                        'term' => 'profile',
                        'type' => 'primary',
                    ]);
                    $progress('project_search_completed', ['total' => 1]);
                    $progress('candidate_ranking_completed', [
                        'candidateCount' => count($analysisResult['candidates'] ?? []),
                    ]);
                    $progress('comparison_ai_started', [
                        'candidateCount' => count($analysisResult['candidates'] ?? []),
                    ]);
                    $progress('comparison_ai_completed', []);
                    $progress('assessment_completed', []);
                    $progress('analysis_completed', []);
                }

                return $analysisResult;
            },
            static function (string $targetPath, array $result, array $moduleInfo) use ($state, $pdfFailure): string {
                ++$state->pdfCalls;
                if ($pdfFailure !== null) {
                    throw $pdfFailure;
                }

                return '/tmp/module-review.pdf';
            },
        );
        $input = new ArrayInput([]);
        $input->setInteractive(true);
        $input->setStream(self::inputStream($inputContents));
        $output = new BufferedOutput();
        $result = $workflow->run($input, $output, new QuestionHelper(), self::module());

        return [$result, $state, $output->fetch()];
    }

    /** @return array{type: 'local-drush', workingDirectory: string, command: list<string>} */
    private static function runtime(): array
    {
        return ['type' => 'local-drush', 'workingDirectory' => '/tmp', 'command' => ['drush']];
    }

    /** @return array{aiEnabled: bool, providerId: string, modelId: string} */
    private static function availableAiContext(): array
    {
        return ['aiEnabled' => true, 'providerId' => 'local-provider', 'modelId' => 'local-model'];
    }

    private static function module(): ModuleContext
    {
        return new ModuleContext(
            new ResolvedModule(
                '/tmp/example',
                'example',
                '/tmp/example/example.info.yml',
                '/tmp',
                'web',
            ),
            new ModuleInfo('Example', 'module', '^11', []),
        );
    }

    /** @return list<array{path: string, bytes: int, content: string}> */
    private static function sourceFiles(int $bytes): array
    {
        $content = '<?php\n' . str_repeat('x', max(0, $bytes - 6));

        return [['path' => 'src/Example.php', 'bytes' => strlen($content), 'content' => $content]];
    }

    /** @return array<string, mixed> */
    private static function completeReviewResult(string $assessmentStatus = 'candidate_with_changes'): array
    {
        return [
            'complete' => true,
            'source' => ['redactions' => [['type' => 'credential'], ['type' => 'token']]],
            'review' => [
                'reusability' => ['status' => 'reusable'],
                'contrib_readiness' => ['status' => 'ready'],
            ],
            'candidates' => [['machineName' => 'existing_profiles', 'title' => 'Existing Profiles']],
            'comparison' => [
                'verdict' => 'possible_overlap',
                'candidates' => [['machine_name' => 'existing_profiles', 'assessment' => 'possible_overlap']],
            ],
            'assessment' => ['status' => $assessmentStatus],
            'ai' => [
                'review' => [
                    'providerId' => 'local-provider',
                    'modelId' => 'local-model',
                    'tokenUsage' => ['input' => 10, 'output' => 5, 'total' => 15],
                ],
                'comparison' => [
                    'providerId' => 'local-provider',
                    'modelId' => 'local-model',
                    'tokenUsage' => ['input' => 10, 'output' => 5, 'total' => 15],
                ],
            ],
        ];
    }

    /** @return resource */
    private static function inputStream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
