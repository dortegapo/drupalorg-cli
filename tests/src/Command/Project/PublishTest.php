<?php

namespace mglaman\DrupalOrg\Tests\Command\Project;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Action\Project\GenerateProjectMetadataAction;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Project\ModuleReviewAnalyzerFactory;
use mglaman\DrupalOrg\Project\PreparedModuleSource;
use mglaman\DrupalOrg\Action\Project\InspectProjectRepositoryAction;
use mglaman\DrupalOrg\Action\Project\InspectLocalProjectSourceAction;
use mglaman\DrupalOrg\Project\ProjectRepositoryUrls;
use mglaman\DrupalOrg\Result\Project\ProjectRepositoryResult;
use mglaman\DrupalOrg\Result\Project\ProjectLocalSourceResult;
use mglaman\DrupalOrgCli\Application;
use mglaman\DrupalOrgCli\Command\Project\DrupalOrgProjectCreationHandoff;
use mglaman\DrupalOrgCli\Command\Project\ModuleReviewWorkflow;
use mglaman\DrupalOrgCli\Command\Project\Publish;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

#[CoversClass(Publish::class)]
class PublishTest extends TestCase
{
    /**
     * @var array<int, array{request: RequestInterface}>
     */
    private array $history = [];

    private string $originalCwd;

    private string $workDir;

    private bool $useRealLocalSourceInspector = false;

    private ?InspectProjectRepositoryAction $overrideRepositoryInspector = null;

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

    public function testExistingDirectoryPrintsProjectHeader(): void
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

        $exitCode = $tester->execute([
            'module' => 'my_module',
            '--dry-run' => true,
            '--release-version' => '1.0.0',
            '--branch' => '1.x',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('PROJECT PUBLISH', $tester->getDisplay());
        self::assertStringContainsString('DRY RUN', $tester->getDisplay());
        self::assertStringNotContainsString('not implemented yet', $tester->getDisplay());
    }

    public function testVerboseProjectHeaderIncludesModuleDetails(): void
    {
        $modulePath = $this->createValidModule();
        chdir($this->workDir);

        $tester = $this->commandTester(self::jsonResponse([
            'list' => [[
                'nid' => '123',
                'title' => 'My Module',
                'field_project_machine_name' => 'my_module',
            ]],
        ]));

        self::assertSame(0, $tester->execute(
            ['module' => 'my_module', '--dry-run' => true, '--release-version' => '1.0.0', '--branch' => '1.x'],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE],
        ));
        $display = $tester->getDisplay();
        $resolvedPath = realpath($modulePath);
        self::assertStringContainsString('Module path: ' . $resolvedPath, $display);
        self::assertStringContainsString('Info file: ' . $resolvedPath . '/my_module.info.yml', $display);
        self::assertStringContainsString('Module type: module', $display);
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

        self::assertSame(0, $tester->execute([
            'module' => 'my_module', '--dry-run' => true, '--release-version' => '1.0.0', '--branch' => '1.x',
        ]));
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
        $tester->setInputs(['y', 'y', 'y', 'n', 'cancel']);

        self::assertSame(0, $tester->execute(['module' => 'my_module']));
        self::assertSame(1, $command->metadataCalls);
        self::assertStringContainsString('Generate Drupal.org project metadata automatically?', $tester->getDisplay());
        self::assertStringContainsString('Drupal.org project metadata', $tester->getDisplay());
        self::assertStringContainsString('Summary', $tester->getDisplay());
        self::assertStringContainsString('This uses one additional AI request. Module source is not sent again.', $tester->getDisplay());
        self::assertStringNotContainsString('Selected source is locally filtered/redacted on a best-effort basis', $tester->getDisplay());
        self::assertStringNotContainsString('Metadata AI:', $tester->getDisplay());
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

    public function testManualHandoffFailureReturnsFailure(): void
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

        self::assertSame(
            1,
            $tester->execute(
                ['module' => 'my_module'],
                ['capture_stderr_separately' => true],
            ),
        );
        self::assertStringContainsString('Could not check Drupal.org project:', $tester->getErrorOutput());
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

        $exitCode = $tester->execute(
            ['module' => 'my_module'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Could not check Drupal.org project:', $tester->getErrorOutput());
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
        self::assertStringContainsString('DRY RUN', $tester->getDisplay());
        self::assertStringContainsString('Project creation is required first.', $tester->getDisplay());
        self::assertStringContainsString('No changes were made.', $tester->getDisplay());

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
            '--release-version' => '1.0.0',
            '--branch' => '1.x',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Drupal.org: PROJECT EXISTS', $tester->getDisplay());
        self::assertStringContainsString(
            'Composer package',
            $tester->getDisplay()
        );
        self::assertStringNotContainsString(
            'Create Drupal.org project "my_module".',
            $tester->getDisplay()
        );
        self::assertStringContainsString(
            'No changes were made.',
            $tester->getDisplay()
        );
    }

    public function testDryRunDoesNotRetryNetworkRepositoryFailures(): void
    {
        $this->createValidModule();
        chdir($this->workDir);
        $inspector = $this->createMock(InspectProjectRepositoryAction::class);
        $inspector->expects(self::once())
            ->method('__invoke')
            ->with('my_module')
            ->willReturn(new ProjectRepositoryResult('read', false, failure: 'network_failure'));
        $tester = $this->commandTesterWithInspector(
            $inspector,
            self::jsonResponse(['list' => [[
                'nid' => '123',
                'title' => 'My Module',
                'field_project_machine_name' => 'my_module',
            ]]]),
        );

        self::assertSame(1, $tester->execute([
            'module' => 'my_module',
            '--dry-run' => true,
            '--release-version' => '1.0.0',
            '--branch' => '1.x',
        ]));
        self::assertStringContainsString('network_failure', $tester->getDisplay());
    }

    public function testLocalUnbornHeadStopsBeforeDrupalOrgAndAiWorkflow(): void
    {
        $this->createValidModule();
        $process = new Process(['git', 'init', $this->workDir]);
        $process->mustRun();
        chdir($this->workDir);
        $this->useRealLocalSourceInspector = true;
        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );

        self::assertSame(1, $tester->execute(['module' => 'my_module'], ['interactive' => false]));
        self::assertSame(0, $command->runtimeCalls);
        self::assertSame(0, $command->inspectCalls);
        self::assertSame(0, $command->analyzeCalls);
        self::assertSame(0, $command->metadataCalls);
        self::assertSame(0, $command->browserCalls);
        self::assertCount(0, $this->history);
        self::assertStringContainsString('Module source requires an initial Git commit', $tester->getDisplay());
    }

    public function testDryRunDescribesBootstrapWithoutMutatingOrCallingDrupalOrg(): void
    {
        $modulePath = $this->createValidModule();
        chdir($this->workDir);
        $this->useRealLocalSourceInspector = true;
        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );

        self::assertSame(0, $tester->execute(['module' => 'my_module', '--dry-run' => true], ['interactive' => false]));
        self::assertStringContainsString('Local Git repository', $tester->getDisplay());
        self::assertStringContainsString('Initial module commit', $tester->getDisplay());
        self::assertStringContainsString('No changes were made.', $tester->getDisplay());
        self::assertFileDoesNotExist($modulePath . '/.git');
        self::assertSame(0, $command->runtimeCalls);
        self::assertCount(0, $this->history);
    }

    public function testDirtyCommittedModuleStopsBeforeDrupalOrgAndAiWorkflow(): void
    {
        $modulePath = $this->createValidModule();
        $this->runGit(['init', $this->workDir], $this->workDir);
        $this->runGit(['config', 'user.name', 'Test User'], $this->workDir);
        $this->runGit(['config', 'user.email', 'test@example.test'], $this->workDir);
        $this->runGit(['add', '--', 'web/modules/custom/my_module'], $this->workDir);
        $this->runGit(['commit', '-m', 'Initial module'], $this->workDir);
        file_put_contents($modulePath . '/my_module.info.yml', "name: Changed\ntype: module\ncore_version_requirement: ^11\n");
        chdir($this->workDir);
        $this->useRealLocalSourceInspector = true;
        [$tester, $command] = $this->commandTesterWithReview(
            self::jsonResponse(['list' => []]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );

        self::assertSame(1, $tester->execute(['module' => 'my_module'], ['interactive' => false]));
        self::assertSame(0, $command->runtimeCalls);
        self::assertSame(0, $command->inspectCalls);
        self::assertSame(0, $command->analyzeCalls);
        self::assertSame(0, $command->metadataCalls);
        self::assertSame(0, $command->browserCalls);
        self::assertCount(0, $this->history);
        self::assertStringContainsString('Module source contains uncommitted changes', $tester->getDisplay());
    }

    public function testInteractiveBootstrapCommitsThenContinuesWorkflow(): void
    {
        $modulePath = $this->createValidModule();
        chdir($this->workDir);
        $this->useRealLocalSourceInspector = true;
        $this->overrideRepositoryInspector = $this->createMock(InspectProjectRepositoryAction::class);
        $this->overrideRepositoryInspector->method('__invoke')->willReturn(
            new ProjectRepositoryResult('read', false, failure: 'network_failure'),
        );
        [$tester] = $this->commandTesterWithReview(
            self::jsonResponse([
                'list' => [[
                    'nid' => '123',
                    'title' => 'My Module',
                    'field_project_machine_name' => 'my_module',
                ]],
            ]),
            self::runtime(),
            self::availableAiContext(),
            self::completeReviewResult(),
        );
        $tester->setInputs(['y', 'Test User', 'test@example.test']);

        self::assertSame(1, $tester->execute([
            'module' => 'my_module',
            '--release-version' => '1.0.0',
            '--branch' => '1.x',
        ]));
        self::assertFileExists($modulePath . '/.git');
        self::assertStringContainsString('Initial my_module implementation', $this->runGit(
            ['log', '-1', '--pretty=%s'],
            $modulePath,
        ));
        self::assertStringContainsString('Could not inspect Drupal.org repository', $tester->getDisplay());
        self::assertStringNotContainsString('ambiguous argument', $tester->getDisplay());
        self::assertFileDoesNotExist($modulePath . '/unrelated.txt');
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

    /** @param list<string> $args */
    private function runGit(array $args, string $cwd): string
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(30);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function commandTester(Response ...$responses): CommandTester
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $client = new Client(false, $stack);
        $command = $this->publishCommand($client);

        return new CommandTester($command);
    }

    private function commandTesterWithInspector(InspectProjectRepositoryAction $inspector, Response ...$responses): CommandTester
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $client = new Client(false, $stack);
        return new CommandTester($this->publishCommand($client, repositoryInspector: $inspector));
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
            public PreparedModuleSource|array|null $analyzePreparedSource = null;
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
            static function (string $modulePath) use ($client): array|PreparedModuleSource {
                return (new ModuleReviewAnalyzerFactory($client))
                    ->create()
                    ->prepareSource($modulePath);
            },
            static function (
                string $modulePath,
                string $machineName,
                array $runtime,
                ?callable $progress = null,
                ?string $humanName = null,
                PreparedModuleSource|array|null $preparedSource = null,
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
                        'categories' => ['Site structure'],
                        'ecosystem' => [],
                        'supporting_organizations' => [],
                        'issues' => [
                            'components' => ['Code', 'Documentation'],
                            'submission_guidelines' => '',
                            'summary_template' => '',
                            'enable_tracker' => true,
                        ],
                        'resources' => [
                            'homepage' => '',
                            'changelog' => '',
                            'demo' => '',
                            'documentation' => [],
                            'screenshots' => [],
                        ],
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
        $command = $this->publishCommand($client, $workflow, $metadataAction, $handoff);

        $command->setHelperSet(new HelperSet(['question' => new QuestionHelper()]));

        return [new CommandTester($command), $state];
    }

    private function publishCommand(
        Client $client,
        ?ModuleReviewWorkflow $reviewWorkflow = null,
        ?GenerateProjectMetadataAction $metadataAction = null,
        ?DrupalOrgProjectCreationHandoff $projectCreationHandoff = null,
        ?InspectLocalProjectSourceAction $localSourceInspector = null,
        ?InspectProjectRepositoryAction $repositoryInspector = null,
    ): Publish {
        $localSourceInspector = $this->useRealLocalSourceInspector
            ? new InspectLocalProjectSourceAction()
            : $this->localSourceInspector();
        $repositoryInspector ??= $this->overrideRepositoryInspector;
        return new class(
            $client,
            $reviewWorkflow,
            $metadataAction,
            $projectCreationHandoff,
            $localSourceInspector,
            $repositoryInspector,
        ) extends Publish {
            public function __construct(
                private readonly Client $testClient,
                ?ModuleReviewWorkflow $reviewWorkflow,
                ?GenerateProjectMetadataAction $metadataAction,
                ?DrupalOrgProjectCreationHandoff $projectCreationHandoff,
                ?InspectLocalProjectSourceAction $localSourceInspector,
                ?InspectProjectRepositoryAction $repositoryInspector,
            ) {
                parent::__construct(
                    $reviewWorkflow,
                    $metadataAction,
                    $projectCreationHandoff,
                    localSourceInspector: $localSourceInspector,
                    repositoryInspector: $repositoryInspector ?? new InspectProjectRepositoryAction(
                        new ProjectRepositoryUrls('/definitely/not/a/repository', '/definitely/not/a/repository'),
                    ),
                );
            }

            protected function initialize(InputInterface $input, OutputInterface $output): void
            {
                parent::initialize($input, $output);
                $this->client = $this->testClient;
            }
        };
    }

    private function localSourceInspector(): InspectLocalProjectSourceAction
    {
        $inspector = $this->createMock(InspectLocalProjectSourceAction::class);
        $inspector->method('__invoke')->willReturn(new ProjectLocalSourceResult('/tmp', '', 'commit', 'tree'));
        return $inspector;
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
                'purpose' => 'Provides reusable profile records.',
                'capabilities' => ['Create profile records', 'List profile records'],
                'drupal_concepts' => ['content entities'],
                'integrations' => [],
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
        return self::completeReviewResult();
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
