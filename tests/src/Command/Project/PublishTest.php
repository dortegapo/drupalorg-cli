<?php

namespace mglaman\DrupalOrg\Tests\Command\Project;

use mglaman\DrupalOrgCli\Application;
use mglaman\DrupalOrgCli\Command\Project\Publish;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Publish::class)]
class PublishTest extends TestCase
{
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
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(0, $exitCode);
        self::assertSame("Module path: " . realpath($modulePath) . "\n", $tester->getDisplay());
    }

    public function testNonexistentPathFails(): void
    {
        $modulePath = $this->workDir . '/no_existe';
        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Module path does not exist: ' . $modulePath, $tester->getDisplay());
    }

    public function testFileFails(): void
    {
        $modulePath = $this->workDir . '/module.txt';
        file_put_contents($modulePath, '');
        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Module path is not a directory: ' . $modulePath, $tester->getDisplay());
    }

    public function testRelativePathUsesCurrentWorkingDirectory(): void
    {
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        chdir($this->workDir);

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => './web/modules/custom/my_module']);

        self::assertSame(0, $exitCode);
        self::assertSame("Module path: " . realpath($modulePath) . "\n", $tester->getDisplay());
    }

    public function testModuleArgumentIsRequired(): void
    {
        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "module").');
        $tester->execute([]);
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
