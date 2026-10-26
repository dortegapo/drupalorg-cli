<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Action\Project;

use mglaman\DrupalOrg\Action\Project\ResolveModuleAction;
use mglaman\DrupalOrg\Project\ComposerContextLocator;
use mglaman\DrupalOrg\Project\ModuleInfoReader;
use mglaman\DrupalOrg\Project\ModuleLocator;
use mglaman\DrupalOrg\Result\Project\ResolveModuleResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolveModuleAction::class)]
#[CoversClass(ResolveModuleResult::class)]
final class ResolveModuleActionTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/drupalorg-cli-resolve-' . uniqid();
        mkdir($this->workDirectory . '/my_module', 0777, true);
        file_put_contents(
            $this->workDirectory . '/my_module/my_module.info.yml',
            "name: My Module\ntype: module\ncore_version_requirement: ^11\n",
        );
    }

    protected function tearDown(): void
    {
        $file = $this->workDirectory . '/my_module/my_module.info.yml';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir(dirname($file))) {
            rmdir(dirname($file));
        }
        if (is_dir($this->workDirectory)) {
            rmdir($this->workDirectory);
        }
    }

    public function testReturnsAnImmutableSerializableResult(): void
    {
        $action = new ResolveModuleAction(
            new ModuleLocator(new ComposerContextLocator()),
            new ModuleInfoReader(),
        );

        $result = $action($this->workDirectory . '/my_module');

        self::assertInstanceOf(ResolveModuleResult::class, $result);
        self::assertSame('my_module', $result->module->location->machineName);
        self::assertSame('My Module', $result->module->info->name);
        self::assertSame([
            'resolved_path' => realpath($this->workDirectory . '/my_module'),
            'machine_name' => 'my_module',
            'info_file' => realpath($this->workDirectory . '/my_module') . '/my_module.info.yml',
            'project_root' => null,
            'web_root' => null,
            'name' => 'My Module',
            'type' => 'module',
            'core_version_requirement' => '^11',
            'dependencies' => [],
        ], $result->jsonSerialize());
    }
}
