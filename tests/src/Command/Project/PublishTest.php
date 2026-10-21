<?php

namespace mglaman\DrupalOrg\Tests\Command\Project;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Action\Project\GenerateProjectMetadataAction;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Project\ModuleReviewAnalyzerFactory;
use mglaman\DrupalOrgCli\Application;
use mglaman\DrupalOrgCli\Command\Project\DrupalOrgProjectCreationHandoff;
use mglaman\DrupalOrgCli\Command\Project\ModuleReviewWorkflow;
use mglaman\DrupalOrgCli\Command\Project\Publish;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Publish::class)]
class PublishTest extends TestCase
{
    /**
     * @var array<int, array{request: RequestInterface}>
     */
    private array $history = [];

    private string $originalCwd;

    private string $workDir;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->workDir = sys_get_temp_dir() . '/drupalorg-cli-publish-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        self::removeDirectory($this->workDir);
    }

    public function testExistingDirectoryPrintsResolvedModulePath(): void
    {
        $modulePath = $this->createValidModule();
        chdir($this->workDir);

        $tester = $this->commandTester(self::jsonResponse([
            'list' => [
                [
                    'nid' => '123',
                    'title' => 'My Module',
                    'field_project_machine_name' => 'my_module',
                ],
            ],
        ]));

        $exitCode = $tester->execute(['module' => 'my_module']);

        self::assertSame(0, $exitCode);
        $resolvedPath = realpath($modulePath);
        self::assertSame(
            "\nModule\n\n"
            . "Module path: $resolvedPath\n"
            . "Machine name: my_module\n"
            . "Module info file: $resolvedPath/my_module.info.yml\n"
            . "Module name: My Module\n"
            . "Module type: module\n"
            . "Core version requirement: ^11\n\n"
            . "Drupal.org project exists: my_module\n",
            $tester->getDisplay()
        );
    }

    public function testMissingDrupalOrgProjectIsReported(): void
    {
        $this->createValidModule();
        chdir($this->workDir);

        $tester = $this->commandTester(self::jsonResponse(['list' => []]));

        $exitCode = $tester->execute(
            ['module' => 'my_module'],
            ['interactive' => false],
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Machine name: my_module', $tester->getDisplay());
        self::assertStringContainsString(
            'Drupal.org project does not exist: my_module',
            $tester->getDisplay()
        );
    }

    public function testExistingProjectDoesNotInspectDrupalAi(): void
    {
        $this->createValidModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse([
                'list' => [
                    [
                        'nid' => '123',
                        'title' => 'My Module',
                        'field_project_machine_name' => 'my_module',
                    ],
                ],
            ]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertSame(0, $command->runtimeCalls);
        self::assertSame(0, $command->inspectCalls);
        self::assertSame(0, $command->analyzeCalls);
        self::assertStringNotContainsString('Full report:', $tester->getDisplay());
        self::assertSame(0, $command->pdfCalls);
    }

    public function testDryRunDoesNotInspectOrRunTheAnalyzer(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );

        self::assertSame(0, $tester->execute([
            'module' => 'my_module',
            '--dry-run' => true,
        ]));
        self::assertSame(0, $command->runtimeCalls);
        self::assertSame(0, $command->inspectCalls);
        self::assertSame(0, $command->analyzeCalls);
        self::assertSame(0, $command->pdfCalls);
    }

    public function testNonInteractiveNewProjectDoesNotInspectOrRunTheAnalyzer(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );

        self::assertSame(0, $tester->execute(
            ['module' => 'my_module'],
            ['interactive' => false],
        ));
        self::assertSame(0, $command->runtimeCalls);
        self::assertSame(0, $command->inspectCalls);
        self::assertSame(0, $command->analyzeCalls);
        self::assertSame(0, $command->pdfCalls);
    }

    public function testNoBrowserPrintsCreationUrlWithoutOpeningIt(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            null,
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $tester->setInputs(['cancel']);

        self::assertSame(0, $tester->execute([
            'module' => 'my_module',
            '--no-browser' => true,
        ]));
        self::assertSame(0, $command->browserCalls);
        self::assertStringContainsString(
            'https://www.drupal.org/node/add/project-module',
            $tester->getDisplay(),
        );
    }

    public function testManualHandoffOpensCreationPageAndVerifiesProjectAfterEnter(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            null,
            self::availableAiContext(),
            self::completeReviewResult(),
            null,
            null,
            null,
            null,
            self::jsonResponse([
                'list' => [[
                    'nid' => '123',
                    'title' => 'My Module',
                    'field_project_machine_name' => 'my_module',
                ]],
            ]),
        );
        $tester->setInputs(['']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertSame(1, $command->browserCalls);
        self::assertSame(
            ['https://www.drupal.org/node/add/project-module'],
            $command->openedUrls,
        );
        self::assertStringContainsString('Drupal.org project found: my_module', $tester->getDisplay());
    }

    public function testTechnicalFailureDuringManualHandoffReturnsFailure(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            null,
            self::availableAiContext(),
            self::completeReviewResult(),
            null,
            null,
            null,
            null,
            new Response(500, [], 'Server error'),
        );
        $tester->setInputs(['']);

        self::assertSame(1, $tester->execute(['module' => 'my_module']));
        self::assertStringContainsString('Could not check Drupal.org project:', $tester->getDisplay());
    }

    public function testMetadataCanBeGeneratedAfterAcceptedReview(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::characterizedReviewResult(),
        );
        $tester->setInputs(['y', 'y', 'y', 'cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertSame(1, $command->metadataCalls);
        self::assertStringContainsString('Generate Drupal.org project metadata automatically?', $tester->getDisplay());
        self::assertStringContainsString('Drupal.org project metadata', $tester->getDisplay());
        self::assertStringContainsString('Metadata AI: metadata-provider / metadata-model', $tester->getDisplay());
    }

    public function testMetadataGenerationCanBeSkipped(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::characterizedReviewResult(),
        );
        $tester->setInputs(['y', 'y', 'n', 'cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertSame(0, $command->metadataCalls);
        self::assertStringNotContainsString('Metadata AI:', $tester->getDisplay());
    }

    public function testMetadataFailureDoesNotBlockManualHandoff(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::characterizedReviewResult(),
            null,
            null,
            null,
            new \RuntimeException('metadata failed'),
        );
        $tester->setInputs(['y', 'y', 'y', 'cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertSame(1, $command->metadataCalls);
        self::assertStringContainsString(
            'Could not generate Drupal.org project metadata automatically.',
            $tester->getDisplay(),
        );
        self::assertStringContainsString('You can enter the project metadata manually on Drupal.org.', $tester->getDisplay());
        self::assertStringContainsString('Opening Drupal.org project creation page...', $tester->getDisplay());
    }

    public function testUnavailableRuntimeContinuesWithoutReview(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            null,
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $tester->setInputs(['cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertStringContainsString(
            'Drupal AI review is not available for this project environment.',
            $tester->getDisplay(),
        );
        self::assertSame(1, $command->runtimeCalls);
        self::assertSame(0, $command->inspectCalls);
        self::assertSame(0, $command->analyzeCalls);
    }

    public function testUnavailableAiContinuesWithoutReview(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            [
                'aiEnabled' => false,
                'providerId' => null,
                'modelId' => null,
            ],
            self::completeReviewResult(),
        );
        $tester->setInputs(['cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertStringContainsString(
            'Drupal AI review is not available for this project environment.',
            $tester->getDisplay(),
        );
        self::assertSame(1, $command->runtimeCalls);
        self::assertSame(1, $command->inspectCalls);
        self::assertSame(0, $command->analyzeCalls);
    }

    public function testAiInspectionFailureContinuesWithoutReview(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
            new \RuntimeException('Inspection failed.'),
        );
        $tester->setInputs(['cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertStringContainsString(
            'Drupal AI review is not available for this project environment.',
            $tester->getDisplay(),
        );
        self::assertSame(1, $command->inspectCalls);
        self::assertSame(0, $command->analyzeCalls);
    }

    public function testRejectingReviewDoesNotRunTheAnalyzer(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $tester->setInputs(['', 'cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertStringContainsString('Run AI-assisted contribution review? [y/N]', $tester->getDisplay());
        self::assertSame(0, $command->analyzeCalls);
        self::assertSame(0, $command->pdfCalls);
    }

    public function testAcceptedReviewRunsOnceShowsSummaryAndContinues(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $command->pdfPath = $this->workDir . '/my_module-drupalorg-contribution-review.pdf';
        $tester->setInputs(['y', 'y', 'cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        $display = $tester->getDisplay();
        self::assertSame(1, $command->analyzeCalls);
        self::assertSame(realpath($this->workDir . '/web/modules/custom/my_module'), $command->analyzeModulePath);
        self::assertSame('my_module', $command->analyzeMachineName);
        self::assertSame('My Module', $command->analyzeHumanName);
        self::assertSame(self::runtime(), $command->analyzeRuntime);
        self::assertSame(1, $command->pdfCalls);
        self::assertStringContainsString('Optional AI-assisted contribution review', $display);
        self::assertStringContainsString('Provider: local-provider', $display);
        self::assertStringContainsString('Model: local-model', $display);
        self::assertStringContainsString(
            'best-effort local redaction',
            $display,
        );
        self::assertStringContainsString('similar functionality', $display);
        self::assertStringContainsString('possible functional overlap', $display);
        self::assertStringContainsString('generate a PDF report', $display);
        self::assertStringContainsString('Only the selected module is analyzed.', $display);
        self::assertStringContainsString('The review is advisory only.', $display);
        self::assertStringContainsString('Module source prepared.', $display);
        self::assertStringContainsString('Analyzing module with AI...', $display);
        self::assertStringContainsString('Module analysis completed.', $display);
        self::assertStringContainsString('Searching Drupal.org projects... 1/1 (primary: profile)', $display);
        self::assertStringContainsString('Candidate projects found: 1', $display);
        self::assertStringContainsString('Comparing 1 Drupal.org candidates with AI...', $display);
        self::assertStringContainsString('Candidate comparison completed.', $display);
        self::assertStringContainsString('Contribution assessment completed.', $display);
        self::assertStringContainsString('Assessment: Candidate with changes', $display);
        self::assertStringContainsString('Reusability: Reusable', $display);
        self::assertStringContainsString('Contrib readiness: Ready', $display);
        self::assertStringContainsString('Drupal.org overlap: Possible Overlap', $display);
        self::assertStringContainsString('Candidate projects evaluated: 1', $display);
        self::assertStringContainsString('Sensitive values redacted locally: 2', $display);
        self::assertStringContainsString('AI #1: local-provider / local-model', $display);
        self::assertStringContainsString('AI #2: local-provider / local-model', $display);
        self::assertStringContainsString('Tokens used: input 20, output 10, total 30', $display);
        self::assertStringContainsString('Potential overlaps:', $display);
        self::assertStringContainsString('Existing Profiles (existing_profiles)', $display);
        self::assertStringContainsString('  Functional assessment: Possible Overlap', $display);
        self::assertStringContainsString('PDF report generated.', $display);
        self::assertStringContainsString('Full report: ' . $command->pdfPath, $display);
        self::assertStringContainsString('Continue with project publishing? [y/N]', $display);
        self::assertStringContainsString('Drupal.org project does not exist: my_module', $display);
        self::assertTrue(
            (int) strpos($display, 'Drupal.org project does not exist: my_module')
            < (int) strpos($display, 'Optional AI-assisted contribution review'),
        );
    }

    public function testLargeModuleOffersOnlyBudgetsBelowEligibleSource(): void
    {
        $this->createOversizedModule(180 * 1024);
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $tester->setInputs(['y', '1', 'n']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('  1. 128 KiB — Recommended', $display);
        self::assertStringContainsString('  2. Full module', $display);
        self::assertStringContainsString('  3. Cancel AI review', $display);
        self::assertStringNotContainsString('[0]', $display);
        self::assertSame(1, substr_count($display, '128 KiB — Recommended'));
        self::assertStringContainsString('128 KiB — Recommended', $display);
        self::assertStringContainsString('Full module', $display);
        self::assertStringContainsString('Cancel AI review', $display);
        self::assertStringNotContainsString('256 KiB', $display);
        self::assertStringNotContainsString('512 KiB', $display);
        self::assertSame(1, $command->analyzeCalls);
        self::assertNotNull($command->analyzePreparedSource);
        self::assertSame('selected', $command->analyzePreparedSource['coverage']['mode']);
        self::assertLessThanOrEqual(128 * 1024, $command->analyzePreparedSource['totalBytes']);
    }

    public function testModuleWithinRecommendedBudgetUsesFullSourceWithoutASelector(): void
    {
        $this->createOversizedModule(80 * 1024);
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $tester->setInputs(['y', 'n']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Source mode: Full module.', $display);
        self::assertStringNotContainsString('Choose how much source', $display);
        self::assertSame(1, $command->analyzeCalls);
        self::assertNotNull($command->analyzePreparedSource);
        self::assertSame('full', $command->analyzePreparedSource['coverage']['mode']);
        self::assertSame(
            $command->analyzePreparedSource['coverage']['eligibleFiles'],
            $command->analyzePreparedSource['coverage']['selectedFiles'],
        );
    }

    public function testMediumModuleCanSelectTheLargerAvailableBudget(): void
    {
        $this->createOversizedModule(390 * 1024);
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $tester->setInputs(['y', '2', 'n']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('  1. 128 KiB — Recommended', $display);
        self::assertStringContainsString('  2. 256 KiB', $display);
        self::assertStringContainsString('  3. Full module', $display);
        self::assertStringContainsString('  4. Cancel AI review', $display);
        self::assertStringNotContainsString('512 KiB', $display);
        self::assertSame(1, $command->analyzeCalls);
        self::assertNotNull($command->analyzePreparedSource);
        self::assertSame(256 * 1024, $command->analyzePreparedSource['coverage']['requestedBudgetBytes']);
        self::assertLessThanOrEqual(256 * 1024, $command->analyzePreparedSource['totalBytes']);
    }

    public function testFullModuleCanBeSelectedAboveTheLargestRecommendedBudget(): void
    {
        $this->createOversizedModule(900 * 1024);
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $tester->setInputs(['y', '4', 'n']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertSame(1, $command->analyzeCalls);
        self::assertNotNull($command->analyzePreparedSource);
        self::assertSame('full', $command->analyzePreparedSource['coverage']['mode']);
        self::assertGreaterThan(512 * 1024, $command->analyzePreparedSource['totalBytes']);
        self::assertSame(
            $command->analyzePreparedSource['coverage']['eligibleBytes'],
            $command->analyzePreparedSource['coverage']['selectedBytes'],
        );
    }

    public function testSourceBudgetSelectionCanBeCancelled(): void
    {
        $this->createOversizedModule(180 * 1024);
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $tester->setInputs(['y', '3', 'cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertSame(0, $command->analyzeCalls);
        self::assertStringContainsString('AI-assisted contribution review skipped.', $tester->getDisplay());
    }

    public function testCancellingAfterReviewStopsBeforeTheCurrentFlow(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $command->pdfPath = $this->workDir . '/my_module-drupalorg-contribution-review.pdf';
        $tester->setInputs(['y', 'n']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertSame(1, $command->analyzeCalls);
        self::assertStringContainsString('Project publishing cancelled.', $tester->getDisplay());
        self::assertStringContainsString('Drupal.org project does not exist: my_module', $tester->getDisplay());
        self::assertStringContainsString('Full report: ' . $command->pdfPath, $tester->getDisplay());
        self::assertSame(1, $command->pdfCalls);
    }

    public function testNotCandidateYetStillAsksToContinue(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult('not_candidate_yet'),
        );
        $tester->setInputs(['y', 'y', 'cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertSame(1, $command->analyzeCalls);
        self::assertStringContainsString('Assessment: Not a candidate yet', $tester->getDisplay());
        self::assertStringContainsString('Continue with project publishing? [y/N]', $tester->getDisplay());
    }

    public function testIncompleteReviewHasNoAssessmentAndStillAsksToContinue(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            [
                'complete' => false,
                'source' => ['redactions' => []],
                'limitations' => [
                    [
                        'stage' => 'project_search',
                        'message' => 'Drupal.org project search could not be completed.',
                    ],
                ],
            ],
        );
        $tester->setInputs(['y', 'y', 'cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        $display = $tester->getDisplay();
        self::assertSame(1, $command->analyzeCalls);
        self::assertStringContainsString('AI-assisted contribution review', $display);
        self::assertStringContainsString('AI module review could not be completed.', $display);
        self::assertStringContainsString('Stage: project_search', $display);
        self::assertStringNotContainsString('Assessment:', $display);
        self::assertStringContainsString('Continue with project publishing? [y/N]', $display);
    }

    public function testPdfFailureDoesNotInvalidateReviewOrBlockContinuation(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
            null,
            new \RuntimeException('PDF failed.'),
        );
        $tester->setInputs(['y', 'n']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        $display = $tester->getDisplay();
        self::assertSame(1, $command->pdfCalls);
        self::assertStringContainsString('Could not generate the PDF review report.', $display);
        self::assertStringContainsString('The AI review results are still available in the terminal summary.', $display);
        self::assertStringContainsString('Assessment: Candidate with changes', $display);
        self::assertStringContainsString('Project publishing cancelled.', $display);
    }

    public function testReviewConsentIsRequiredBeforeAnalyzerExecution(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $tester->setInputs(['y', 'n', 'cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        $display = $tester->getDisplay();
        self::assertTrue(
            (int) strpos($display, 'Optional AI-assisted contribution review')
            < (int) strpos($display, 'Run AI-assisted contribution review? [y/N]'),
        );
        self::assertSame(1, $command->analyzeCalls);
    }

    public function testPartialTokenUsageIsReportedAsUnavailable(): void
    {
        $this->createDiscoverableModule();
        chdir($this->workDir);

        $reviewResult = self::completeReviewResult();
        $reviewResult['ai']['review']['tokenUsage'] = [
            'input' => 100,
            'output' => null,
            'total' => null,
        ];
        $reviewResult['ai']['comparison']['tokenUsage'] = [
            'input' => 200,
            'output' => null,
            'total' => null,
        ];
        [$tester] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            $reviewResult,
        );
        $tester->setInputs(['y', 'n']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertStringContainsString(
            'Tokens used: input 300, output unavailable, total unavailable',
            $tester->getDisplay(),
        );
        self::assertStringNotContainsString('output 0', $tester->getDisplay());
        self::assertStringNotContainsString('total 0', $tester->getDisplay());
    }

    public function testPublishDoesNotRegisterReviewOptions(): void
    {
        $command = (new Application())->find('project:publish');

        self::assertFalse($command->getDefinition()->hasOption('review'));
        self::assertFalse($command->getDefinition()->hasOption('ai-metadata'));
    }

    public function testNoBrowserOptionIsRegisteredAsValueNone(): void
    {
        $command = (new Application())->find('project:publish');

        self::assertTrue($command->getDefinition()->hasOption('no-browser'));
        self::assertFalse($command->getDefinition()->getOption('no-browser')->acceptValue());
    }

    public function testDrupalOrgErrorIsNotReportedAsMissingProject(): void
    {
        $this->createValidModule();
        chdir($this->workDir);

        $tester = $this->commandTester(new Response(500, [], 'Server error'));

        $exitCode = $tester->execute(['module' => 'my_module']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Could not check Drupal.org project:', $tester->getDisplay());
        self::assertStringNotContainsString('project does not exist', $tester->getDisplay());
    }

    public function testDryRunOptionIsRegisteredAsValueNone(): void
    {
        $command = (new Application())->find('project:publish');

        self::assertTrue($command->getDefinition()->hasOption('dry-run'));
        self::assertFalse($command->getDefinition()->getOption('dry-run')->acceptValue());
    }

    public function testDryRunReportsPlanForMissingDrupalOrgProject(): void
    {
        $modulePath = $this->createValidModule();
        chdir($this->workDir);

        $tester = $this->commandTester(self::jsonResponse(['list' => []]));

        $exitCode = $tester->execute([
            'module' => 'my_module',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        $resolvedPath = realpath($modulePath);
        self::assertSame(
            "Dry run: no changes will be made.\n\n"
            . "Module path: $resolvedPath\n"
            . "Machine name: my_module\n"
            . "Module info file: $resolvedPath/my_module.info.yml\n"
            . "Module name: My Module\n"
            . "Module type: module\n"
            . "Core version requirement: ^11\n"
            . "Drupal.org project exists: no\n\n"
            . "Planned actions:\n"
            . "  1. Offer an optional AI-assisted contribution review and local PDF report.\n"
            . "  2. Optionally generate Drupal.org project metadata from the AI module characterization.\n"
            . "  3. Open the Drupal.org project creation page for manual project creation.\n"
            . "  4. Verify that the Drupal.org project exists.\n"
            . "  5. Wait for the Drupal.org Git repository to become available.\n"
            . "  6. Prepare an isolated temporary workspace.\n"
            . "  7. Copy the module into the temporary workspace.\n"
            . "  8. Initialize the publication Git repository.\n"
            . "  9. Create the initial commit.\n"
            . "  10. Configure the Drupal.org Git remote.\n"
            . "  11. Push the initial branch.\n\n"
            . "Publication execution is not implemented yet.\n"
            . "Dry run complete. No changes were made.\n",
            $tester->getDisplay()
        );

        self::assertCount(1, $this->history);
        self::assertSame('GET', $this->sentRequest()->getMethod());
        self::assertSame(
            'https://www.drupal.org/api-d7/node.json?field_project_machine_name=my_module',
            (string) $this->sentRequest()->getUri()
        );
    }

    public function testDryRunReportsPlanForExistingDrupalOrgProject(): void
    {
        $this->createValidModule();
        chdir($this->workDir);

        $tester = $this->commandTester(self::jsonResponse([
            'list' => [
                [
                    'nid' => '123',
                    'title' => 'My Module',
                    'field_project_machine_name' => 'my_module',
                ],
            ],
        ]));

        $exitCode = $tester->execute([
            'module' => 'my_module',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Drupal.org project exists: yes', $tester->getDisplay());
        self::assertStringContainsString(
            'Use the existing Drupal.org project "my_module".',
            $tester->getDisplay()
        );
        self::assertStringNotContainsString(
            'Create Drupal.org project "my_module".',
            $tester->getDisplay()
        );
        self::assertStringContainsString(
            'Publication execution is not implemented yet.',
            $tester->getDisplay()
        );
    }

    public function testInvalidYamlFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents($modulePath . '/my_module.info.yml', "name: [invalid\n");

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Could not parse module info file:', $tester->getDisplay());
    }

    public function testYamlWithoutNameFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents($modulePath . '/my_module.info.yml', "type: module\ncore_version_requirement: ^11\n");

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Missing required "name" in my_module.info.yml.',
            $tester->getDisplay()
        );
    }

    public function testYamlWithEmptyNameFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents(
            $modulePath . '/my_module.info.yml',
            "name: \"\"\ntype: module\ncore_version_requirement: ^11\n"
        );

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Required "name" in my_module.info.yml must be a non-empty string.',
            $tester->getDisplay()
        );
    }

    public function testYamlWithoutTypeFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents($modulePath . '/my_module.info.yml', "name: My Module\ncore_version_requirement: ^11\n");

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Missing required "type" in my_module.info.yml.',
            $tester->getDisplay()
        );
    }

    public function testYamlWithThemeTypeFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents(
            $modulePath . '/my_module.info.yml',
            "name: My Module\ntype: theme\ncore_version_requirement: ^11\n"
        );

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Expected Drupal project type "module", got "theme".',
            $tester->getDisplay()
        );
    }

    public function testYamlWithoutCoreVersionRequirementFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents($modulePath . '/my_module.info.yml', "name: My Module\ntype: module\n");

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Missing required "core_version_requirement" in my_module.info.yml.',
            $tester->getDisplay()
        );
    }

    public function testYamlWithEmptyCoreVersionRequirementFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents(
            $modulePath . '/my_module.info.yml',
            "name: My Module\ntype: module\ncore_version_requirement: \"\"\n"
        );

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Required "core_version_requirement" in my_module.info.yml must be a non-empty string.',
            $tester->getDisplay()
        );
    }

    public function testModuleArgumentIsRequired(): void
    {
        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "module").');
        $tester->execute([]);
    }

    private function createValidModule(): string
    {
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        file_put_contents(
            $modulePath . '/my_module.info.yml',
            "name: My Module\ntype: module\ncore_version_requirement: ^11\n"
        );

        return $modulePath;
    }

    private function createDiscoverableModule(): string
    {
        $modulePath = $this->createValidModule();
        file_put_contents($this->workDir . '/composer.json', '{}');

        return $modulePath;
    }

    private function createOversizedModule(int $bytes): string
    {
        $modulePath = $this->createDiscoverableModule();
        $sourceDirectory = $modulePath . '/src';
        mkdir($sourceDirectory);
        $remaining = $bytes;
        $index = 0;
        while ($remaining > 0) {
            $chunk = min(8192, $remaining);
            $prefix = "<?php\n";
            $content = $prefix . str_repeat('x', max(0, $chunk - strlen($prefix)));
            file_put_contents($sourceDirectory . '/Source' . $index . '.php', $content);
            $remaining -= strlen($content);
            ++$index;
        }

        return $modulePath;
    }

    private function commandTester(Response ...$responses): CommandTester
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $client = new Client(false, $stack);
        $command = new Publish(clientOverride: $client);

        return new CommandTester($command);
    }

    /**
     * @param array<string, mixed>|null $runtime
     * @param array<string, mixed> $aiContext
     * @param array<string, mixed> $analysisResult
     * @param \RuntimeException|null $inspectFailure
     * @param \RuntimeException|null $pdfFailure
     * @param array<string, mixed>|null $metadataResult
     * @param \RuntimeException|null $metadataFailure
     * @param Response ...$additionalResponses
     *
     * @return array{0: CommandTester, 1: object}
     */
    private function commandTesterWithReview(
        Response $response,
        ?array $runtime,
        array $aiContext,
        array $analysisResult,
        ?\RuntimeException $inspectFailure = null,
        ?\RuntimeException $pdfFailure = null,
        ?array $metadataResult = null,
        ?\RuntimeException $metadataFailure = null,
        Response ...$additionalResponses,
    ): array {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([$response, ...$additionalResponses]));
        $stack->push(Middleware::history($this->history));
        $client = new Client(false, $stack);
        $state = new class {
            public int $runtimeCalls = 0;
            public int $inspectCalls = 0;
            public int $analyzeCalls = 0;
            public int $pdfCalls = 0;
            public int $metadataCalls = 0;
            public int $browserCalls = 0;
            /** @var list<string> */
            public array $openedUrls = [];
            public string $pdfPath = '/tmp/module-review.pdf';
            public ?string $analyzeModulePath = null;
            public ?string $analyzeMachineName = null;
            public ?string $analyzeHumanName = null;
            /** @var array<string, mixed>|null */
            public ?array $analyzeRuntime = null;
            /** @var array<string, mixed>|null */
            public ?array $analyzePreparedSource = null;
        };

        $workflow = new ModuleReviewWorkflow(
            $client,
            static function (?string $projectRoot) use ($state, $runtime): ?array {
                ++$state->runtimeCalls;
                return $runtime;
            },
            static function (array $runtime) use ($state, $aiContext, $inspectFailure): array {
                ++$state->inspectCalls;
                if ($inspectFailure !== null) {
                    throw $inspectFailure;
                }

                return $aiContext;
            },
            static function (string $modulePath) use ($client): array {
                return (new ModuleReviewAnalyzerFactory($client))->create()->prepareSource($modulePath);
            },
            static function (
                string $modulePath,
                string $machineName,
                array $runtime,
                ?callable $progress = null,
                ?string $humanName = null,
                ?array $preparedSource = null,
            ) use (
                $state,
                $analysisResult,
            ): array {
                ++$state->analyzeCalls;
                $state->analyzeModulePath = $modulePath;
                $state->analyzeMachineName = $machineName;
                $state->analyzeHumanName = $humanName;
                $state->analyzeRuntime = $runtime;
                $state->analyzePreparedSource = $preparedSource;
                if ($progress !== null && ($analysisResult['complete'] ?? false) === true) {
                    $progress('source_prepared', ['files' => 1]);
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

                return $state->pdfPath;
            },
        );

        $metadataAction = new GenerateProjectMetadataAction(
            static function (...$arguments) use ($state, $metadataResult, $metadataFailure): array {
                ++$state->metadataCalls;
                if ($metadataFailure !== null) {
                    throw $metadataFailure;
                }

                return $metadataResult ?? [
                    'providerId' => 'metadata-provider',
                    'modelId' => 'metadata-model',
                    'result' => [
                        'summary' => 'Generated summary.',
                        'description' => 'Generated description.',
                        'features' => ['Generated feature'],
                        'integrations' => [],
                    ],
                    'tokenUsage' => ['input' => null, 'output' => null, 'total' => null],
                ];
            },
        );

        $handoff = new DrupalOrgProjectCreationHandoff(
            $client,
            static function (string $url, OutputInterface $stdErr, OutputInterface $stdOut) use ($state): void {
                ++$state->browserCalls;
                $state->openedUrls[] = $url;
            },
        );
        $command = new Publish($workflow, $metadataAction, $handoff, $client);

        $command->setHelperSet(new HelperSet(['question' => new QuestionHelper()]));

        return [new CommandTester($command), $state];
    }

    /**
     * @return array{
     *     type: 'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * }
     */
    private static function runtime(): array
    {
        return [
            'type' => 'local-drush',
            'workingDirectory' => '/tmp',
            'command' => ['drush'],
        ];
    }

    /**
     * @return array{aiEnabled: bool, providerId: string, modelId: string}
     */
    private static function availableAiContext(): array
    {
        return [
            'aiEnabled' => true,
            'providerId' => 'local-provider',
            'modelId' => 'local-model',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function completeReviewResult(string $assessmentStatus = 'candidate_with_changes'): array
    {
        return [
            'complete' => true,
            'source' => [
                'redactions' => [
                    ['type' => 'credential'],
                    ['type' => 'token'],
                ],
            ],
            'review' => [
                'reusability' => ['status' => 'reusable'],
                'contrib_readiness' => ['status' => 'ready'],
            ],
            'candidates' => [
                ['machineName' => 'existing_profiles', 'title' => 'Existing Profiles'],
            ],
            'comparison' => [
                'verdict' => 'possible_overlap',
                'candidates' => [
                    [
                        'machine_name' => 'existing_profiles',
                        'assessment' => 'possible_overlap',
                    ],
                ],
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

    /**
     * @return array<string, mixed>
     */
    private static function characterizedReviewResult(): array
    {
        $result = self::completeReviewResult();
        $result['review'] = [
            'purpose' => 'Provides reusable profile records.',
            'capabilities' => ['Create profile records', 'List profile records'],
            'drupal_concepts' => ['content entities'],
            'integrations' => [],
            'reusability' => ['status' => 'reusable'],
        ];

        return $result;
    }

    private function sentRequest(): RequestInterface
    {
        self::assertArrayHasKey(0, $this->history);

        return $this->history[0]['request'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(array $body): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    private static function removeDirectory(string $directory): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
