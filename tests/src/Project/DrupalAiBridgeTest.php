<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\DrupalAiBridge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DrupalAiBridge::class)]
class DrupalAiBridgeTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/drupalorg-cli-ai-bridge-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDir);
    }

    public function testInspectsConfiguredProviderAndModel(): void
    {
        self::assertSame(
            [
                'aiEnabled' => true,
                'providerId' => 'openai',
                'modelId' => 'gpt-5.4',
            ],
            $this->runBridge(
                '__DRUPALORG_CLI_AI_CONTEXT__={"ai_enabled":true,"provider_id":"openai","model_id":"gpt-5.4"}'
            )
        );
    }

    public function testAcceptsAnArbitraryProvider(): void
    {
        self::assertSame(
            [
                'aiEnabled' => true,
                'providerId' => 'some_provider',
                'modelId' => 'some-model',
            ],
            $this->runBridge(
                '__DRUPALORG_CLI_AI_CONTEXT__={"ai_enabled":true,"provider_id":"some_provider","model_id":"some-model"}'
            )
        );
    }

    public function testReportsDisabledAi(): void
    {
        self::assertSame(
            [
                'aiEnabled' => false,
                'providerId' => null,
                'modelId' => null,
            ],
            $this->runBridge(
                '__DRUPALORG_CLI_AI_CONTEXT__={"ai_enabled":false,"provider_id":null,"model_id":null}'
            )
        );
    }

    public function testReportsEnabledAiWithoutDefault(): void
    {
        self::assertSame(
            [
                'aiEnabled' => true,
                'providerId' => null,
                'modelId' => null,
            ],
            $this->runBridge(
                '__DRUPALORG_CLI_AI_CONTEXT__={"ai_enabled":true,"provider_id":null,"model_id":null}'
            )
        );
    }

    public function testIgnoresAdditionalOutputAroundMarkedResponse(): void
    {
        self::assertSame(
            [
                'aiEnabled' => true,
                'providerId' => 'x',
                'modelId' => 'y',
            ],
            $this->runBridge(
                "Drupal warning\nSome other text\n"
                . '__DRUPALORG_CLI_AI_CONTEXT__={"ai_enabled":true,"provider_id":"x","model_id":"y"}'
                . "\nMore text\n"
            )
        );
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI bridge returned an invalid response.');

        $this->runBridge('__DRUPALORG_CLI_AI_CONTEXT__={broken');
    }

    public function testRejectsMissingMarker(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI bridge returned an invalid response.');

        $this->runBridge('normal drush output');
    }

    public function testRejectsInvalidTypes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI bridge returned an invalid response.');

        $this->runBridge('__DRUPALORG_CLI_AI_CONTEXT__={"ai_enabled":"yes","provider_id":123,"model_id":[]}');
    }

    public function testReportsProcessFailureWithoutExposingOutput(): void
    {
        try {
            $this->runBridge('', 7, null, 'private stderr');
            self::fail('Expected the bridge to report the process failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Could not inspect Drupal AI configuration (exit code 7).',
                $exception->getMessage()
            );
            self::assertStringNotContainsString('private stderr', $exception->getMessage());
        }
    }

    public function testPassesTheDrupalAiInspectionScriptThroughStdin(): void
    {
        $capturedInput = $this->workDir . '/stdin.txt';

        $this->runBridge(
            '__DRUPALORG_CLI_AI_CONTEXT__={"ai_enabled":false,"provider_id":null,"model_id":null}',
            0,
            $capturedInput
        );

        $script = file_get_contents($capturedInput);
        self::assertIsString($script);
        self::assertStringContainsString('ai.provider', $script);
        self::assertStringContainsString('chat_with_structured_response', $script);
        self::assertStringNotContainsString('<?php', $script);
        self::assertStringNotContainsString('->chat(', $script);
    }

    public function testStructuredChatReturnsStructuredResultAndTokenUsage(): void
    {
        self::assertSame(
            [
                'providerId' => 'some_provider',
                'modelId' => 'some_model',
                'result' => [
                    'status' => 'ok',
                    'items' => ['a', 'b'],
                ],
                'tokenUsage' => [
                    'input' => 100,
                    'output' => 20,
                    'total' => 120,
                ],
            ],
            $this->runStructuredBridge(
                self::structuredOutput([
                    'ok' => true,
                    'provider_id' => 'some_provider',
                    'model_id' => 'some_model',
                    'result' => [
                        'status' => 'ok',
                        'items' => ['a', 'b'],
                    ],
                    'token_usage' => [
                        'input' => 100,
                        'output' => 20,
                        'total' => 120,
                    ],
                ])
            )
        );
    }

    public function testStructuredChatAcceptsNullTokenUsage(): void
    {
        self::assertSame(
            [
                'providerId' => 'some_provider',
                'modelId' => 'some_model',
                'result' => ['status' => 'ok'],
                'tokenUsage' => [
                    'input' => null,
                    'output' => null,
                    'total' => null,
                ],
            ],
            $this->runStructuredBridge(
                self::structuredOutput([
                    'ok' => true,
                    'provider_id' => 'some_provider',
                    'model_id' => 'some_model',
                    'result' => ['status' => 'ok'],
                    'token_usage' => [
                        'input' => null,
                        'output' => null,
                        'total' => null,
                    ],
                ])
            )
        );
    }

    public function testStructuredChatReportsDisabledAi(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI is not enabled in the target Drupal project.');

        $this->runStructuredBridge(
            self::structuredOutput([
                'ok' => false,
                'error' => 'ai_disabled',
            ])
        );
    }

    public function testStructuredChatReportsMissingProvider(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Drupal AI has no default structured chat provider/model configured.'
        );

        $this->runStructuredBridge(
            self::structuredOutput([
                'ok' => false,
                'error' => 'no_structured_provider',
            ])
        );
    }

    public function testStructuredChatRejectsInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI bridge returned an invalid structured response.');

        $this->runStructuredBridge('__DRUPALORG_CLI_AI_RESULT__={broken');
    }

    public function testStructuredChatRejectsMissingMarker(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI bridge returned an invalid structured response.');

        $this->runStructuredBridge('normal drush output');
    }

    public function testStructuredChatRejectsInvalidEnvelopeTypes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI bridge returned an invalid structured response.');

        $this->runStructuredBridge(
            self::structuredOutput([
                'ok' => 'yes',
                'provider_id' => 'some_provider',
                'model_id' => 'some_model',
                'result' => [],
                'token_usage' => [
                    'input' => 1,
                    'output' => 1,
                    'total' => 2,
                ],
            ])
        );
    }

    public function testStructuredChatRejectsMissingProviderOrModel(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI bridge returned an invalid structured response.');

        $this->runStructuredBridge(
            self::structuredOutput([
                'ok' => true,
                'result' => [],
                'token_usage' => [
                    'input' => 1,
                    'output' => 1,
                    'total' => 2,
                ],
            ])
        );
    }

    public function testStructuredChatRejectsNonArrayResult(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI bridge returned an invalid structured response.');

        $this->runStructuredBridge(
            self::structuredOutput([
                'ok' => true,
                'provider_id' => 'some_provider',
                'model_id' => 'some_model',
                'result' => 'invalid',
                'token_usage' => [
                    'input' => 1,
                    'output' => 1,
                    'total' => 2,
                ],
            ])
        );
    }

    public function testStructuredChatRejectsInvalidTokenUsageTypes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI bridge returned an invalid structured response.');

        $this->runStructuredBridge(
            self::structuredOutput([
                'ok' => true,
                'provider_id' => 'some_provider',
                'model_id' => 'some_model',
                'result' => [],
                'token_usage' => [
                    'input' => '1',
                    'output' => 1,
                    'total' => 2,
                ],
            ])
        );
    }

    public function testStructuredChatReportsProcessFailureWithoutExposingOutput(): void
    {
        try {
            $this->runStructuredBridge('', 'test prompt', ['type' => 'object'], 7, null, 'private stderr');
            self::fail('Expected the bridge to report the process failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Could not execute the structured Drupal AI request (exit code 7).',
                $exception->getMessage()
            );
            self::assertStringNotContainsString('private stderr', $exception->getMessage());
        }
    }

    public function testStructuredChatRejectsEmptyPromptBeforeStartingProcess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The Drupal AI prompt cannot be empty.');

        (new DrupalAiBridge())->structuredChat(
            $this->invalidRuntime(),
            '',
            ['type' => 'object']
        );
    }

    public function testStructuredChatRejectsWhitespacePromptBeforeStartingProcess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The Drupal AI prompt cannot be empty.');

        (new DrupalAiBridge())->structuredChat(
            $this->invalidRuntime(),
            " \t\n",
            ['type' => 'object']
        );
    }

    public function testStructuredChatRejectsEmptySchemaBeforeStartingProcess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The Drupal AI JSON schema cannot be empty.');

        (new DrupalAiBridge())->structuredChat(
            $this->invalidRuntime(),
            'test prompt',
            []
        );
    }

    public function testStructuredChatPassesSafePayloadAndExpectedScriptThroughStdin(): void
    {
        $capturedInput = $this->workDir . '/structured-stdin.txt';
        $prompt = 'SECRET-TEST-PROMPT-\'"\\PHP';
        $schema = [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
            ],
        ];

        $this->runStructuredBridge(
            self::structuredOutput([
                'ok' => true,
                'provider_id' => 'some_provider',
                'model_id' => 'some_model',
                'result' => ['message' => 'ok'],
                'token_usage' => [
                    'input' => null,
                    'output' => null,
                    'total' => null,
                ],
            ]),
            $prompt,
            $schema,
            0,
            $capturedInput
        );

        $script = file_get_contents($capturedInput);
        self::assertIsString($script);
        self::assertStringContainsString('chat_with_structured_response', $script);
        self::assertStringContainsString('StructuredOutputSchema', $script);
        self::assertStringContainsString('setChatStructuredJsonSchema', $script);
        self::assertStringContainsString('->chat(', $script);
        self::assertStringContainsString('getTokenUsage', $script);
        self::assertStringNotContainsString('<?php', $script);
        self::assertStringNotContainsString($prompt, $script);

        $matches = [];
        self::assertSame(
            1,
            preg_match("/base64_decode\\('([A-Za-z0-9+\\/=]+)'/", $script, $matches)
        );
        self::assertArrayHasKey(1, $matches);
        self::assertIsString($matches[1]);
        $decodedPayload = base64_decode($matches[1], true);
        self::assertIsString($decodedPayload);
        self::assertSame(
            [
                'prompt' => $prompt,
                'schema' => $schema,
            ],
            json_decode($decodedPayload, true, 512, JSON_THROW_ON_ERROR)
        );
    }

    private function runBridge(
        string $stdout,
        int $exitCode = 0,
        ?string $captureInput = null,
        string $stderr = ''
    ): array {
        return (new DrupalAiBridge())->inspect(
            $this->createFakeRuntime($stdout, $exitCode, $captureInput, $stderr)
        );
    }

    /**
     * @param array<string, mixed> $jsonSchema
     *
     * @return array<string, mixed>
     */
    private function runStructuredBridge(
        string $stdout,
        string $prompt = 'test prompt',
        array $jsonSchema = ['type' => 'object'],
        int $exitCode = 0,
        ?string $captureInput = null,
        string $stderr = ''
    ): array {
        return (new DrupalAiBridge())->structuredChat(
            $this->createFakeRuntime($stdout, $exitCode, $captureInput, $stderr),
            $prompt,
            $jsonSchema
        );
    }

    /**
     * @return array{
     *     type: 'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * }
     */
    private function createFakeRuntime(
        string $stdout,
        int $exitCode = 0,
        ?string $captureInput = null,
        string $stderr = ''
    ): array {
        $responseFile = $this->workDir . '/response.txt';
        file_put_contents($responseFile, $stdout);
        $fakeExecutable = $this->workDir . '/fake-drush.php';
        file_put_contents(
            $fakeExecutable,
            <<<'PHP'
<?php
$responseFile = $argv[1];
$exitCode = (int) $argv[2];
$captureFile = $argv[3] ?? '';
$stderr = $argv[4] ?? '';
$input = stream_get_contents(STDIN);
if ($captureFile !== '') {
    file_put_contents($captureFile, $input);
}
if ($stderr !== '') {
    fwrite(STDERR, $stderr);
}
fwrite(STDOUT, (string) file_get_contents($responseFile));
exit($exitCode);
PHP
        );

        $command = [PHP_BINARY, $fakeExecutable, $responseFile, (string) $exitCode];
        $command[] = $captureInput ?? '';
        $command[] = $stderr;

        return [
            'type' => 'local-drush',
            'workingDirectory' => $this->workDir,
            'command' => $command,
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
     * @return array{
     *     type: 'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * }
     */
    private function invalidRuntime(): array
    {
        return [
            'type' => 'local-drush',
            'workingDirectory' => $this->workDir,
            'command' => ['/definitely/not-a-command'],
        ];
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
