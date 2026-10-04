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
    public function testCommandPrintsHelloWorld(): void
    {
        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertSame("Hello world\n", $tester->getDisplay());
    }
}
