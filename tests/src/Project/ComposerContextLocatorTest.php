<?php

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ComposerContextLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerContextLocator::class)]
class ComposerContextLocatorTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/drupalorg-cli-composer-context-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDir);
    }

    public function testFindsComposerContextInStartingDirectory(): void
    {
        $this->writeComposerFile(
            $this->workDir,
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"web"}}}}'
        );

        $contexts = iterator_to_array((new ComposerContextLocator())->findCandidates($this->workDir));

        self::assertSame(
            [
                [
                    'projectRoot' => $this->workDir,
                    'webRoot' => 'web',
                ],
            ],
            $contexts
        );
    }

    public function testFindsComposerContextInParentDirectory(): void
    {
        $nestedDirectory = $this->workDir . '/nested';
        mkdir($nestedDirectory);
        $this->writeComposerFile($this->workDir, '{}');

        $contexts = iterator_to_array((new ComposerContextLocator())->findCandidates($nestedDirectory));

        self::assertSame(
            [
                [
                    'projectRoot' => $this->workDir,
                    'webRoot' => 'web',
                ],
            ],
            $contexts
        );
    }

    public function testFindsSeveralComposerContextsFromNearestToFarthest(): void
    {
        $nestedDirectory = $this->workDir . '/nested';
        $startDirectory = $nestedDirectory . '/deep';
        mkdir($startDirectory, 0755, true);
        $this->writeComposerFile($this->workDir, '{}');
        $this->writeComposerFile(
            $nestedDirectory,
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"docroot"}}}}'
        );

        $contexts = iterator_to_array((new ComposerContextLocator())->findCandidates($startDirectory));

        self::assertSame(
            [
                [
                    'projectRoot' => $nestedDirectory,
                    'webRoot' => 'docroot',
                ],
                [
                    'projectRoot' => $this->workDir,
                    'webRoot' => 'web',
                ],
            ],
            $contexts
        );
    }

    public function testUsesConfiguredDocroot(): void
    {
        $this->writeComposerFile(
            $this->workDir,
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"docroot"}}}}'
        );

        $contexts = iterator_to_array((new ComposerContextLocator())->findCandidates($this->workDir));

        self::assertSame('docroot', $contexts[0]['webRoot']);
    }

    public function testUsesWebFallbackWhenConfigurationIsAbsent(): void
    {
        $this->writeComposerFile($this->workDir, '{"extra":{}}');

        $contexts = iterator_to_array((new ComposerContextLocator())->findCandidates($this->workDir));

        self::assertSame('web', $contexts[0]['webRoot']);
    }

    public function testInvalidComposerJsonIsReportedAfterDiscovery(): void
    {
        $this->writeComposerFile($this->workDir, '{invalid');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not parse composer.json:');
        iterator_to_array((new ComposerContextLocator())->findCandidates($this->workDir));
    }

    public function testDiscoveryTerminatesAtFilesystemRoot(): void
    {
        $startDirectory = $this->workDir . '/nested/deep';
        mkdir($startDirectory, 0755, true);

        $contexts = iterator_to_array((new ComposerContextLocator())->findCandidates($startDirectory));

        self::assertSame([], $contexts);
    }

    private function writeComposerFile(string $directory, string $contents): void
    {
        file_put_contents($directory . '/composer.json', $contents);
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
