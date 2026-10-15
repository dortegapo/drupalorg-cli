<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests;

use mglaman\DrupalOrgCli\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
class ApplicationTest extends TestCase
{
    public function testRegistersAuthCommands(): void
    {
        $application = new Application();

        self::assertTrue($application->has('auth:login'));
        self::assertTrue($application->has('auth:status'));
        self::assertTrue($application->has('auth:logout'));
    }
}
