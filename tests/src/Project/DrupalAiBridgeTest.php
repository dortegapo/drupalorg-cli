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

    private function runBridge(
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

        return (new DrupalAiBridge())->inspect([
            'type' => 'local-drush',
            'workingDirectory' => $this->workDir,
            'command' => $command,
        ]);
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
