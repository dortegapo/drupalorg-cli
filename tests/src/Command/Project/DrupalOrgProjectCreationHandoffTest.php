<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Browser\BrowserCompanionConfigStore;
use mglaman\DrupalOrg\Browser\BrowserCompanionInstallation;
use mglaman\DrupalOrg\Browser\BrowserCompanionManager;
use mglaman\DrupalOrg\Browser\BrowserFamily;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrg\Result\Project\ProjectResources;
use mglaman\DrupalOrg\Result\Project\ProjectIssueSettings;
use mglaman\DrupalOrgCli\Command\Project\DrupalOrgProjectCreationHandoff;
use mglaman\DrupalOrgCli\Command\Project\ProjectCreationHandoffResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(DrupalOrgProjectCreationHandoff::class)]
final class DrupalOrgProjectCreationHandoffTest extends TestCase
{
    public function testManualHandoffPrintsUrlAndResumesWhenProjectExists(): void
    {
        $handoff = new DrupalOrgProjectCreationHandoff($this->client(new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['list' => [[
                'nid' => '1',
                'title' => 'Example',
                'field_project_machine_name' => 'example',
            ]]], JSON_THROW_ON_ERROR),
        )));
        $input = $this->input();
        $output = new BufferedOutput();

        $result = $handoff->run(
            $input,
            $output,
            $output,
            'example',
            'Example',
            'module',
            '^11',
        );

        self::assertSame(ProjectCreationHandoffResult::FOUND, $result->status);
        $display = $output->fetch();
        self::assertStringContainsString(DrupalOrgProjectCreationHandoff::PROJECT_CREATION_URL, $display);
        self::assertStringContainsString('Waiting for Drupal.org to create "example"', $display);
    }

    public function testNoBrowserDoesNotInvokeOpenCallback(): void
    {
        $opened = false;
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client($this->projectResponse()),
            static function () use (&$opened): void {
                $opened = true;
            },
        );
        $output = new BufferedOutput();
        $handoff->run(
            $this->input(),
            $output,
            $output,
            'example',
            'Example',
            'module',
            '^11',
        );

        self::assertFalse($opened);
    }

    public function testPollsWithBackoffAndShowsPollingStatus(): void
    {
        $delays = [];
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client(
                new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['list' => []], JSON_THROW_ON_ERROR),
                ),
                new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['list' => []], JSON_THROW_ON_ERROR),
                ),
                new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['list' => []], JSON_THROW_ON_ERROR),
                ),
                $this->projectResponse(),
            ),
            sleeper: static function (int $seconds) use (&$delays): void {
                $delays[] = $seconds;
            },
        );
        $output = new BufferedOutput();

        $result = $handoff->run(
            $this->input(),
            $output,
            $output,
            'example',
            'Example',
            'module',
            '^11',
        );

        self::assertSame(ProjectCreationHandoffResult::FOUND, $result->status);
        self::assertSame([1, 2, 3], $delays);
        $display = $output->fetch();
        self::assertStringNotContainsString('Polling Drupal.org every 1 second.', $display);
        self::assertStringContainsString('Checking Drupal.org project "example"... (attempt 1)', $display);
        self::assertStringContainsString('Checking Drupal.org project "example"... (attempt 3)', $display);
        self::assertStringNotContainsString('Press Enter to verify', $display);
    }

    public function testConfiguredBrowserCompanionReceivesPrefillUrlWithoutPrintingIt(): void
    {
        $opened = [];
        $configDirectory = sys_get_temp_dir() . '/drupalorg-cli-prefill-' . bin2hex(random_bytes(6));
        mkdir($configDirectory, 0700);
        $configPath = $configDirectory . '/config.json';
        $manager = new BrowserCompanionManager(new BrowserCompanionConfigStore($configPath));
        $manager->configure(BrowserFamily::CHROMIUM, BrowserCompanionInstallation::LOCAL);
        try {
            $handoff = new DrupalOrgProjectCreationHandoff(
                $this->client($this->projectResponse()),
                static function (string $url) use (&$opened): void {
                    $opened[] = $url;
                },
                browserCompanionManager: $manager,
            );
            $output = new BufferedOutput();
            $result = $handoff->run(
                new ArrayInput([], new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)])),
                $output,
                $output,
                'example',
                'Example',
                'module',
                '^11',
                $this->metadata(),
            );

            self::assertSame(ProjectCreationHandoffResult::FOUND, $result->status);
            self::assertCount(1, $opened);
            self::assertStringContainsString('drupalorg-cli-prefill=', $opened[0]);
            self::assertStringNotContainsString('drupalorg-cli-prefill=', $output->fetch());
        } finally {
            @unlink($configPath);
            @rmdir($configDirectory);
        }
    }

    private function input(): ArrayInput
    {
        $input = new ArrayInput(
            ['--no-browser' => true],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $input->setInteractive(true);

        return $input;
    }

    private function projectResponse(): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['list' => [[
                'nid' => '1',
                'title' => 'Example',
                'field_project_machine_name' => 'example',
            ]]], JSON_THROW_ON_ERROR),
        );
    }

    private function client(Response ...$responses): Client
    {
        return new Client(false, HandlerStack::create(new MockHandler($responses)));
    }

    private function metadata(): ProjectMetadataResult
    {
        return new ProjectMetadataResult(
            'Example',
            'example',
            'module',
            '^11',
            [],
            'A short summary.',
            'Generated description.',
            ['One feature'],
            [],
            'openai',
            'test',
            ['input' => null, 'output' => null, 'total' => null],
            [],
            [],
            [],
            new ProjectIssueSettings(),
            new ProjectResources(),
        );
    }
}
