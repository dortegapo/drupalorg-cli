<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Entity\Project;
use mglaman\DrupalOrg\Entity\Release;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use mglaman\DrupalOrgCli\Command\Project\DrupalOrgReleaseCreationHandoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(DrupalOrgReleaseCreationHandoff::class)]
final class DrupalOrgReleaseCreationHandoffTest extends TestCase
{
    public function testNoBrowserWaitsAutomaticallyWithoutOpening(): void
    {
        $browserCalls = 0;
        $launcher = new BrowserLauncher(
            commandExists: static function () use (&$browserCalls): bool {
                ++$browserCalls;
                return true;
            },
            processRunner: static function () use (&$browserCalls): bool {
                ++$browserCalls;
                return true;
            },
        );
        $release = Release::fromStdClass(json_decode(
            file_get_contents(__DIR__ . '/../../../fixtures/release_node.json'),
            false,
            512,
            JSON_THROW_ON_ERROR,
        ));
        $client = $this->createMock(Client::class);
        $client->method('getProjectReleases')->willReturn([$release]);
        $handoff = new DrupalOrgReleaseCreationHandoff($client, $launcher);
        $input = new ArrayInput(
            ['--no-browser' => true],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $input->setInteractive(true);
        $input->setStream($this->inputStream(''));
        $output = new BufferedOutput();

        self::assertTrue($handoff->run(
            $input,
            $output,
            $output,
            new QuestionHelper(),
            new Project('123', 'Example', 'example'),
            ProjectReleaseVersion::fromString('10.6.3'),
        ));
        self::assertSame(0, $browserCalls);
        $display = $output->fetch();
        self::assertStringContainsString('Waiting for Drupal.org to create release "10.6.3"...', $display);
        self::assertStringNotContainsString('Press Enter to verify', $display);
    }

    public function testPollsUntilReleaseIsAvailable(): void
    {
        $release = Release::fromStdClass(json_decode(
            file_get_contents(__DIR__ . '/../../../fixtures/release_node.json'),
            false,
            512,
            JSON_THROW_ON_ERROR,
        ));
        $client = $this->createMock(Client::class);
        $client->method('getProjectReleases')->willReturnOnConsecutiveCalls([], [], [$release]);
        $delays = [];
        $handoff = new DrupalOrgReleaseCreationHandoff(
            $client,
            sleeper: static function (int $seconds) use (&$delays): void {
                $delays[] = $seconds;
            },
        );
        $input = new ArrayInput(
            ['--no-browser' => true],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $input->setInteractive(true);
        $input->setStream($this->inputStream(''));
        $output = new BufferedOutput();

        self::assertTrue($handoff->run(
            $input,
            $output,
            $output,
            new QuestionHelper(),
            new Project('123', 'Example', 'example'),
            ProjectReleaseVersion::fromString('10.6.3'),
        ));
        self::assertSame([2, 3], $delays);
        self::assertStringContainsString('FOUND Drupal.org release: 10.6.3', $output->fetch());
    }

    /** @return resource */
    private function inputStream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        return $stream;
    }
}
