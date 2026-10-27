<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests;

use mglaman\DrupalOrgCli\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
class ApplicationTest extends TestCase
{
    public function testRegistersProductCommandsWithoutAuthCommands(): void
    {
        $application = new Application();

        self::assertTrue($application->has('project:publish'));
        self::assertTrue($application->has('browser:setup'));
        self::assertFalse($application->has('auth:login'));
        self::assertFalse($application->has('auth:status'));
        self::assertFalse($application->has('auth:logout'));
    }

    public function testKeepsGlobalVersionOption(): void
    {
        $application = new Application();

        self::assertTrue($application->getDefinition()->hasOption('version'));
        self::assertTrue($application->find('project:publish')->getDefinition()->hasOption('release-version'));
        self::assertFalse($application->find('project:publish')->getDefinition()->hasOption('version'));
    }
}
