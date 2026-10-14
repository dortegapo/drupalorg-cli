<?php

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ComposerContextLocator;
use mglaman\DrupalOrg\Project\ModuleLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleLocator::class)]
class ModuleLocatorTest extends TestCase
{
    private string $originalCwd;

    private string $workDir;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->workDir = sys_get_temp_dir() . '/drupalorg-cli-module-locator-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        self::removeDirectory($this->workDir);
    }

    public function testAbsolutePath(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents($modulePath . '/my_module.info.yml', '');

        $location = (new ModuleLocator(new ComposerContextLocator()))->locate($modulePath);
        $resolvedPath = realpath($modulePath);

        self::assertIsString($resolvedPath);
        self::assertSame(
            [
                'resolvedPath' => $resolvedPath,
                'moduleName' => 'my_module',
                'infoFile' => $resolvedPath . '/my_module.info.yml',
            ],
            $location
        );
    }

    public function testRelativePathUsesCurrentWorkingDirectory(): void
    {
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        file_put_contents($modulePath . '/my_module.info.yml', '');
        chdir($this->workDir);

        $location = (new ModuleLocator(new ComposerContextLocator()))->locate('./web/modules/custom/my_module');
        $resolvedPath = realpath($modulePath);

        self::assertIsString($resolvedPath);
        self::assertSame($resolvedPath, $location['resolvedPath']);
        self::assertSame('my_module', $location['moduleName']);
        self::assertSame($resolvedPath . '/my_module.info.yml', $location['infoFile']);
    }

    public function testMachineNameUsesCurrentWorkingDirectory(): void
    {
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        file_put_contents($modulePath . '/my_module.info.yml', '');
        chdir($this->workDir);

        $location = (new ModuleLocator(new ComposerContextLocator()))->locate('my_module');
        $resolvedPath = realpath($modulePath);

        self::assertIsString($resolvedPath);
        self::assertSame($resolvedPath, $location['resolvedPath']);
        self::assertSame('my_module', $location['moduleName']);
        self::assertSame($resolvedPath . '/my_module.info.yml', $location['infoFile']);
    }

    public function testMachineNameFromWebSubdirectory(): void
    {
        file_put_contents(
            $this->workDir . '/composer.json',
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"web/"}}}}'
        );
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        file_put_contents($modulePath . '/my_module.info.yml', '');
        chdir($this->workDir . '/web');

        $location = (new ModuleLocator(new ComposerContextLocator()))->locate('my_module');
        $resolvedPath = realpath($modulePath);

        self::assertIsString($resolvedPath);
        self::assertSame($resolvedPath, $location['resolvedPath']);
    }

    public function testMachineNameFromArbitrarySubdirectory(): void
    {
        file_put_contents(
            $this->workDir . '/composer.json',
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"web/"}}}}'
        );
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        file_put_contents($modulePath . '/my_module.info.yml', '');
        $workingDirectory = $this->workDir . '/scripts/tools';
        mkdir($workingDirectory, 0755, true);
        chdir($workingDirectory);

        $location = (new ModuleLocator(new ComposerContextLocator()))->locate('my_module');
        $resolvedPath = realpath($modulePath);

        self::assertIsString($resolvedPath);
        self::assertSame($resolvedPath, $location['resolvedPath']);
    }

    public function testModuleComposerJsonDoesNotStopParentSearch(): void
    {
        file_put_contents(
            $this->workDir . '/composer.json',
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"web/"}}}}'
        );
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        file_put_contents($modulePath . '/composer.json', '{"name":"my_module"}');
        file_put_contents($modulePath . '/my_module.info.yml', '');
        chdir($modulePath);

        $location = (new ModuleLocator(new ComposerContextLocator()))->locate('my_module');
        $resolvedPath = realpath($modulePath);

        self::assertIsString($resolvedPath);
        self::assertSame($resolvedPath, $location['resolvedPath']);
    }

    public function testInvalidModuleComposerJsonContinuesToParentProject(): void
    {
        file_put_contents(
            $this->workDir . '/composer.json',
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"web/"}}}}'
        );
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        file_put_contents($modulePath . '/composer.json', '{invalid');
        file_put_contents($modulePath . '/my_module.info.yml', '');
        chdir($modulePath);

        $location = (new ModuleLocator(new ComposerContextLocator()))->locate('my_module');
        $resolvedPath = realpath($modulePath);

        self::assertIsString($resolvedPath);
        self::assertSame($resolvedPath, $location['resolvedPath']);
    }

    public function testIntermediateComposerWithoutCandidateContinuesToParentProject(): void
    {
        file_put_contents(
            $this->workDir . '/composer.json',
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"web/"}}}}'
        );
        $nestedDirectory = $this->workDir . '/tools/nested';
        mkdir($nestedDirectory, 0755, true);
        file_put_contents($nestedDirectory . '/composer.json', '{"name":"tools-nested"}');
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        file_put_contents($modulePath . '/my_module.info.yml', '');
        chdir($nestedDirectory);

        $location = (new ModuleLocator(new ComposerContextLocator()))->locate('my_module');
        $resolvedPath = realpath($modulePath);

        self::assertIsString($resolvedPath);
        self::assertSame($resolvedPath, $location['resolvedPath']);
    }

    public function testIntermediateCandidateWithoutInfoFileContinuesToParentProject(): void
    {
        file_put_contents(
            $this->workDir . '/composer.json',
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"web/"}}}}'
        );
        $nestedDirectory = $this->workDir . '/nested';
        mkdir($nestedDirectory, 0755, true);
        file_put_contents(
            $nestedDirectory . '/composer.json',
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"web/"}}}}'
        );
        $intermediateModulePath = $nestedDirectory . '/web/modules/custom/my_module';
        mkdir($intermediateModulePath, 0755, true);
        $parentModulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($parentModulePath, 0755, true);
        file_put_contents($parentModulePath . '/my_module.info.yml', '');
        chdir($nestedDirectory);

        $location = (new ModuleLocator(new ComposerContextLocator()))->locate('my_module');
        $resolvedPath = realpath($parentModulePath);

        self::assertIsString($resolvedPath);
        self::assertSame($resolvedPath, $location['resolvedPath']);
    }

    public function testOnlyIncompleteCandidateFails(): void
    {
        file_put_contents(
            $this->workDir . '/composer.json',
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"web/"}}}}'
        );
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        chdir($this->workDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Module info file does not exist: ' . $modulePath . '/my_module.info.yml'
        );
        (new ModuleLocator(new ComposerContextLocator()))->locate('my_module');
    }

    public function testAlternativeWebRootFromArbitrarySubdirectory(): void
    {
        file_put_contents(
            $this->workDir . '/composer.json',
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"docroot/"}}}}'
        );
        $modulePath = $this->workDir . '/docroot/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        file_put_contents($modulePath . '/my_module.info.yml', '');
        $workingDirectory = $this->workDir . '/scripts';
        mkdir($workingDirectory, 0755, true);
        chdir($workingDirectory);

        $location = (new ModuleLocator(new ComposerContextLocator()))->locate('my_module');
        $resolvedPath = realpath($modulePath);

        self::assertIsString($resolvedPath);
        self::assertSame($resolvedPath, $location['resolvedPath']);
    }

    public function testExplicitPathIgnoresConfiguredWebRoot(): void
    {
        file_put_contents(
            $this->workDir . '/composer.json',
            '{"extra":{"drupal-scaffold":{"locations":{"web-root":"docroot/"}}}}'
        );
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        file_put_contents($modulePath . '/my_module.info.yml', '');

        $location = (new ModuleLocator(new ComposerContextLocator()))->locate($modulePath);
        $resolvedPath = realpath($modulePath);

        self::assertIsString($resolvedPath);
        self::assertSame($resolvedPath, $location['resolvedPath']);
    }

    public function testMachineNameNotFoundFails(): void
    {
        chdir($this->workDir);
        $candidate = $this->workDir . '/web/modules/custom/does_not_exist';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module path does not exist: ' . $candidate);
        (new ModuleLocator(new ComposerContextLocator()))->locate('does_not_exist');
    }

    public function testNonexistentPathFails(): void
    {
        $modulePath = $this->workDir . '/no_existe';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module path does not exist: ' . $modulePath);
        (new ModuleLocator(new ComposerContextLocator()))->locate($modulePath);
    }

    public function testFileFails(): void
    {
        $modulePath = $this->workDir . '/module.txt';
        file_put_contents($modulePath, '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module path is not a directory: ' . $modulePath);
        (new ModuleLocator(new ComposerContextLocator()))->locate($modulePath);
    }

    public function testMissingInfoFileFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        $infoFile = $modulePath . '/my_module.info.yml';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module info file does not exist: ' . $infoFile);
        (new ModuleLocator(new ComposerContextLocator()))->locate($modulePath);
    }

    public function testUnexpectedInfoFileFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents($modulePath . '/foo.info.yml', '');
        $infoFile = $modulePath . '/my_module.info.yml';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module info file does not exist: ' . $infoFile);
        (new ModuleLocator(new ComposerContextLocator()))->locate($modulePath);
    }

    public function testInfoFileDirectoryFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        $infoFile = $modulePath . '/my_module.info.yml';
        mkdir($infoFile);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module info file does not exist: ' . $infoFile);
        (new ModuleLocator(new ComposerContextLocator()))->locate($modulePath);
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
