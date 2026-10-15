<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Auth;

use mglaman\DrupalOrgCli\Command\Auth\AuthCommandFactory;
use mglaman\DrupalOrgCli\Command\Auth\Login;
use mglaman\DrupalOrgCli\Command\Auth\Logout;
use mglaman\DrupalOrgCli\Command\Auth\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthCommandFactory::class)]
class AuthCommandFactoryTest extends TestCase
{
    public function testCreatesAuthCommandsInOrder(): void
    {
        $commands = (new AuthCommandFactory())->create();

        self::assertCount(3, $commands);
        self::assertInstanceOf(Login::class, $commands[0]);
        self::assertInstanceOf(Status::class, $commands[1]);
        self::assertInstanceOf(Logout::class, $commands[2]);
    }
}
