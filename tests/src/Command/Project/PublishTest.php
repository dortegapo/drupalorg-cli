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
        file_put_contents(
            $modulePath . '/my_module.info.yml',
            "name: My Module\ntype: module\ncore_version_requirement: ^11\n"
        );

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(0, $exitCode);
        $resolvedPath = realpath($modulePath);
        self::assertSame(
            "Module path: $resolvedPath\nModule info file: $resolvedPath/my_module.info.yml\nModule name: My Module\nModule type: module\nCore version requirement: ^11\n",
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
