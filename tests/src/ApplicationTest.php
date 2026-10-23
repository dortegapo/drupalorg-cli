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
        self::assertFalse($application->has('auth:login'));
        self::assertFalse($application->has('auth:status'));
        self::assertFalse($application->has('auth:logout'));
    }
}
