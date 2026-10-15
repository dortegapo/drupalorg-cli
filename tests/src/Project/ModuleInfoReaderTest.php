<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ModuleInfoReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;

#[CoversClass(ModuleInfoReader::class)]
class ModuleInfoReaderTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/drupalorg-cli-module-info-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDir);
    }

    public function testReadsValidModuleMetadata(): void
    {
        $infoFile = $this->writeInfo("name: My Module\ntype: module\ncore_version_requirement: ^11\n");

        self::assertSame([
            'name' => 'My Module',
            'type' => 'module',
            'coreVersionRequirement' => '^11',
        ], (new ModuleInfoReader())->read($infoFile, 'my_module'));
    }

    public function testReportsInvalidYamlAndPreservesTheParseException(): void
    {
        $infoFile = $this->writeInfo("name: [invalid\n");

        try {
            (new ModuleInfoReader())->read($infoFile, 'my_module');
            self::fail('Expected invalid YAML to throw an exception.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Could not parse module info file:', $exception->getMessage());
            self::assertInstanceOf(ParseException::class, $exception->getPrevious());
        }
    }

    public function testRejectsYamlThatIsNotAMapping(): void
    {
        $infoFile = $this->writeInfo("scalar value\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module info file must contain a YAML mapping.');
        (new ModuleInfoReader())->read($infoFile, 'my_module');
    }

    public function testRejectsMissingName(): void
    {
        $this->assertMetadataError(
            "type: module\ncore_version_requirement: ^11\n",
            'Missing required "name" in my_module.info.yml.'
        );
    }

    public function testRejectsEmptyName(): void
    {
        $this->assertMetadataError(
            "name: \"\"\ntype: module\ncore_version_requirement: ^11\n",
            'Required "name" in my_module.info.yml must be a non-empty string.'
        );
    }

    public function testRejectsMissingType(): void
    {
        $this->assertMetadataError(
            "name: My Module\ncore_version_requirement: ^11\n",
            'Missing required "type" in my_module.info.yml.'
        );
    }

    public function testRejectsNonModuleType(): void
    {
        $this->assertMetadataError(
            "name: My Module\ntype: theme\ncore_version_requirement: ^11\n",
            'Expected Drupal project type "module", got "theme".'
        );
    }

    public function testRejectsMissingCoreVersionRequirement(): void
    {
        $this->assertMetadataError(
            "name: My Module\ntype: module\n",
            'Missing required "core_version_requirement" in my_module.info.yml.'
        );
    }

    public function testRejectsEmptyCoreVersionRequirement(): void
    {
        $this->assertMetadataError(
            "name: My Module\ntype: module\ncore_version_requirement: \"\"\n",
            'Required "core_version_requirement" in my_module.info.yml must be a non-empty string.'
        );
    }

    private function assertMetadataError(string $contents, string $message): void
    {
        $infoFile = $this->writeInfo($contents);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        (new ModuleInfoReader())->read($infoFile, 'my_module');
    }

    private function writeInfo(string $contents): string
    {
        $infoFile = $this->workDir . '/my_module.info.yml';
        file_put_contents($infoFile, $contents);

        return $infoFile;
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
