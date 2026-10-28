<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\DrupalCoreVersionInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DrupalCoreVersionInspector::class)]
final class DrupalCoreVersionInspectorTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/drupalorg-cli-core-' . uniqid();
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        if (is_file($this->workDirectory . '/runtime.php')) {
            unlink($this->workDirectory . '/runtime.php');
        }
        if (is_dir($this->workDirectory)) {
            rmdir($this->workDirectory);
        }
    }

    public function testReadsDrupalCoreVersionFromTheRuntime(): void
    {
        $this->writeRuntimeScript();

        self::assertSame(
            ['version' => '11.4.8', 'major' => 11],
            (new DrupalCoreVersionInspector())->inspect($this->runtime('__DRUPALORG_CLI_CORE_VERSION__=11.4.8')),
        );
    }

    public function testFindsTheVersionMarkerAmongRuntimeNoise(): void
    {
        $this->writeRuntimeScript();

        self::assertSame(
            ['version' => '11.4.8', 'major' => 11],
            (new DrupalCoreVersionInspector())->inspect($this->runtime("warning before\n__DRUPALORG_CLI_CORE_VERSION__=11.4.8\nwarning after")),
        );
    }

    public function testRejectsMalformedRuntimeOutput(): void
    {
        $this->writeRuntimeScript();

        self::assertNull((new DrupalCoreVersionInspector())->inspect($this->runtime('not-a-version')));
    }

    public function testRejectsOutputWithoutTheVersionMarker(): void
    {
        $this->writeRuntimeScript();

        self::assertNull((new DrupalCoreVersionInspector())->inspect($this->runtime('Drupal version unavailable')));
    }

    public function testDoesNotExposeAProcessFailure(): void
    {
        $this->writeRuntimeScript();

        self::assertNull((new DrupalCoreVersionInspector())->inspect($this->runtime('FAILURE')));
    }

    private function writeRuntimeScript(): void
    {
        file_put_contents(
            $this->workDirectory . '/runtime.php',
            <<<'PHP'
<?php
$value = $argv[1] ?? '';
if ($value === 'FAILURE') {
    fwrite(STDERR, 'private runtime failure');
    exit(1);
}
echo $value;
PHP
        );
    }

    /**
     * @return array{
     *     type: 'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * }
     */
    private function runtime(string $output): array
    {
        return [
            'type' => 'local-drush',
            'workingDirectory' => $this->workDirectory,
            'command' => [PHP_BINARY, $this->workDirectory . '/runtime.php', $output],
        ];
    }
}
