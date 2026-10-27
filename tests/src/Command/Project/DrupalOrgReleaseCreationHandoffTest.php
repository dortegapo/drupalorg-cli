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
    public function testNoBrowserDoesNotOpenWhenUserTypesOpen(): void
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
        $input->setStream($this->inputStream("open\n"));
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
