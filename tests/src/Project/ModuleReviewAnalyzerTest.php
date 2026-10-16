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
            ],
            [
                self::aiSuccess(self::reviewResult()),
                self::aiSuccess(self::comparisonResult([
                    self::comparisonCandidate('profile_project', 'likely_duplicate', ['profiles']),
                ], 'likely_duplicate')),
            ],
            $captureDir,
        );

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime);

        self::assertTrue($result['complete']);
        self::assertSame(
            ['machineName' => 'example_module', 'path' => $this->modulePath],
            $result['module'],
        );
        self::assertSame(1, $result['source']['files']);
        self::assertNotSame(strlen($originalSource), $result['source']['bytes']);
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
            ['profile', 'records', 'directory'],
            array_column($result['searches'], 'term'),
        );
        self::assertCount(3, $this->history);
        foreach ($this->history as $index => $entry) {
            parse_str($entry['request']->getUri()->getQuery(), $query);
            self::assertSame(['profile', 'records', 'directory'][$index], $query['filter']['fulltext']);
            self::assertSame('10', $query['page']['limit']);
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
    }

    public function testReturnsReviewAiFailureWithoutSearching(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $analyzer = $this->analyzer([], [self::aiFailure('provider_failure')]);

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime);

        self::assertIncompleteAt('review_ai', $result);
        self::assertSame([], $result['searches']);
        self::assertSame([], $this->history);
        self::assertNull($result['comparison']);
        self::assertNull($result['assessment']);
    }

    public function testReturnsReviewPromptFailureWhenSourceExceedsTheBuilderBudget(): void
    {
        file_put_contents($this->modulePath . '/module.php', str_repeat('a', 131073));
        $analyzer = $this->analyzer([], []);

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime);

        self::assertIncompleteAt('review_prompt', $result);
        self::assertSame([], $this->history);
        self::assertNull($result['review']);
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

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime);

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
            [self::searchResponse([]), self::searchResponse([]), self::searchResponse([])],
            [
                self::aiSuccess(self::reviewResult()),
                self::aiFailure('provider_failure'),
            ],
        );

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime);

        self::assertIncompleteAt('comparison_ai', $result);
        self::assertNotNull($result['review']);
        self::assertNull($result['comparison']);
        self::assertNull($result['assessment']);
    }

    public function testStopsAfterReviewValidationFailure(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $analyzer = $this->analyzer([], [self::aiSuccess(['unexpected' => 'result'])]);

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime);

        self::assertIncompleteAt('review_validation', $result);
        self::assertSame([], $result['searches']);
    }

    public function testStopsAfterComparisonValidationFailure(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $analyzer = $this->analyzer(
            [self::searchResponse([]), self::searchResponse([]), self::searchResponse([])],
            [
                self::aiSuccess(self::reviewResult()),
                self::aiSuccess(self::comparisonResult([], 'likely_duplicate')),
            ],
        );

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime);

        self::assertIncompleteAt('comparison_validation', $result);
        self::assertNull($result['comparison']);
        self::assertNull($result['assessment']);
    }

    public function testCompletesWithZeroCandidates(): void
    {
        file_put_contents($this->modulePath . '/module.php', '<?php\n');
        $analyzer = $this->analyzer(
            [self::searchResponse([]), self::searchResponse([]), self::searchResponse([])],
            [
                self::aiSuccess(self::reviewResult()),
                self::aiSuccess(self::comparisonResult([], 'no_clear_duplicate')),
            ],
        );

        $result = $analyzer->analyze($this->modulePath, 'example_module', $this->runtime);

        self::assertTrue($result['complete']);
        self::assertSame([], $result['candidates']);
        self::assertSame([], $result['comparison']['candidates']);
        self::assertSame('candidate', $result['assessment']['status']);
        self::assertSame([], $result['limitations']);
    }

    public function testReturnsCollectionFailureForAnInvalidModulePath(): void
    {
        $analyzer = $this->analyzer([], []);

        $result = $analyzer->analyze($this->workDir . '/missing', 'example_module', $this->runtime);

        self::assertIncompleteAt('collection', $result);
        self::assertSame([], $result['source']['skipped']);
    }

    /**
     * @param list<Response|\Throwable> $searchResponses
     * @param list<string> $aiOutputs
     */
    private function analyzer(array $searchResponses, array $aiOutputs, ?string $captureDir = null): ModuleReviewAnalyzer
    {
        $this->history = [];
        $responses = new MockHandler($searchResponses);
        $stack = HandlerStack::create($responses);
        $stack->push(Middleware::history($this->history));

        $this->runtime = $this->createRuntime($aiOutputs, $captureDir);

        return new ModuleReviewAnalyzer(
            new ModuleReviewCollector(),
            new ModuleReviewSensitiveDataSanitizer(),
            new ModuleReviewPromptBuilder(),
            new ModuleReviewResultValidator(),
            new DrupalAiBridge(),
            new DrupalOrgProjectSearch(new Client(false, $stack)),
            new ModuleReviewCandidateRanker(),
            new ModuleReviewComparisonPromptBuilder(),
            new ModuleReviewComparisonResultValidator(),
            new ModuleContributionAssessmentResolver(),
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
            'reusability' => [
                'status' => 'reusable',
                'confidence' => 0.9,
                'findings' => [],
            ],
            'contrib_readiness' => [
                'status' => 'ready',
                'findings' => [],
            ],
            'analysis_limits' => [],
        ];
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
            'differences' => ['Different implementation details.'],
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

    /**
     * @param array<string, mixed> $result
     */
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
