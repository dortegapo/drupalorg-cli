<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Project\DrupalOrgProjectPrefillUrlBuilder;
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrgCli\Command\Project\DrupalOrgProjectCreationHandoff;
use mglaman\DrupalOrgCli\Command\Project\ProjectCreationHandoffResult;
use mglaman\DrupalOrgCli\Command\Project\ProjectPublishProgressRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(DrupalOrgProjectCreationHandoff::class)]
final class DrupalOrgProjectCreationHandoffTest extends TestCase
{
    public function testReportsCreationUrlAndFoundProject(): void
    {
        $opened = [];
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['list' => [[
                    'nid' => '1',
                    'title' => 'Example',
                    'field_project_machine_name' => 'example',
                ]]], JSON_THROW_ON_ERROR),
            )),
            static function (string $url) use (&$opened): void {
                $opened[] = $url;
            },
        );
        $input = new ArrayInput(
            ['--no-browser' => true],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $output = new BufferedOutput();
        $input->setInteractive(true);
        $input->setStream($this->inputStream("\n"));

        $result = $handoff->run(
            $input,
            $output,
            $output,
            new QuestionHelper(),
            'example',
            'Example',
            'module',
            '^11',
        );

        self::assertSame(ProjectCreationHandoffResult::FOUND, $result->status);
        self::assertSame([], $opened);
        self::assertStringContainsString(DrupalOrgProjectCreationHandoff::PROJECT_CREATION_URL, $output->fetch());
    }

    public function testReturnsCancelledWithoutARequest(): void
    {
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client(),
            static function (): void {
            },
        );
        $input = new ArrayInput(
            ['--no-browser' => true],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $input->setInteractive(true);
        $input->setStream($this->inputStream("cancel\n"));

        $result = $handoff->run(
            $input,
            new BufferedOutput(),
            new BufferedOutput(),
            new QuestionHelper(),
            'example',
            'Example',
            'module',
            '^11',
        );

        self::assertSame(ProjectCreationHandoffResult::CANCELLED, $result->status);
    }

    public function testVerboseOutputIncludesProjectContext(): void
    {
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client(),
            static function (): void {
            },
        );
        $input = new ArrayInput(
            ['--no-browser' => true],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $input->setInteractive(true);
        $input->setStream($this->inputStream("cancel\n"));

        $handoff->run(
            $input,
            $output,
            new BufferedOutput(),
            new QuestionHelper(),
            'example',
            'Example',
            'module',
            '^11',
        );

        $display = $output->fetch();
        self::assertStringContainsString('Type          Module', $display);
        self::assertStringContainsString('Core          ^11', $display);
    }

    public function testOpensCreationPageAndVerifiesProject(): void
    {
        $opened = [];
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['list' => [[
                    'nid' => '1',
                    'title' => 'Example',
                    'field_project_machine_name' => 'example',
                ]]], JSON_THROW_ON_ERROR),
            )),
            static function (string $url) use (&$opened): void {
                $opened[] = $url;
            },
        );
        $input = new ArrayInput(
            [],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $output = new BufferedOutput();
        $input->setInteractive(true);
        $input->setStream($this->inputStream("\n"));

        $result = $handoff->run(
            $input,
            $output,
            new BufferedOutput(),
            new QuestionHelper(),
            'example',
            'Example',
            'module',
            '^11',
        );

        self::assertSame(ProjectCreationHandoffResult::FOUND, $result->status);
        self::assertSame([DrupalOrgProjectCreationHandoff::PROJECT_CREATION_URL], $opened);
    }

    public function testProjectNotFoundCompletesVerificationPhaseWithoutFailing(): void
    {
        $output = new BufferedOutput();
        $progress = new ProjectPublishProgressRenderer($output);
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['list' => []], JSON_THROW_ON_ERROR),
            )),
            null,
            null,
            null,
            $progress,
        );
        $input = new ArrayInput(
            ['--no-browser' => true],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $input->setInteractive(true);
        $input->setStream($this->inputStream("\ncancel\n"));

        $result = $handoff->run(
            $input,
            $output,
            $output,
            new QuestionHelper(),
            'example',
            'Example',
            'module',
            '^11',
        );

        self::assertSame(ProjectCreationHandoffResult::CANCELLED, $result->status);
        $display = $output->fetch();
        self::assertStringContainsString('Project not found yet.', $display);
        self::assertStringContainsString('✓ Verifying Drupal.org project', $display);
        self::assertStringNotContainsString('✗ Verifying Drupal.org project', $display);
    }

    public function testPrefillsFormWithMetadataAndDefaultStatuses(): void
    {
        $opened = [];
        $output = new BufferedOutput();
        $progress = new ProjectPublishProgressRenderer($output);
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['list' => [[
                    'nid' => '1',
                    'title' => 'Example',
                    'field_project_machine_name' => 'example',
                ]]], JSON_THROW_ON_ERROR),
            )),
            static function (string $url) use (&$opened): void {
                $opened[] = $url;
            },
            null,
            null,
            $progress,
        );
        $input = new ArrayInput(
            [],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $input->setInteractive(true);
        $input->setStream($this->inputStream("y\n\n"));
        $result = $handoff->run(
            $input,
            $output,
            $output,
            new QuestionHelper(),
            'example',
            'Example',
            'module',
            '^11',
            $this->metadata(),
        );

        self::assertSame(ProjectCreationHandoffResult::FOUND, $result->status);
        self::assertCount(1, $opened);
        self::assertStringStartsWith(
            DrupalOrgProjectPrefillUrlBuilder::PROJECT_CREATION_URL . '#'
            . DrupalOrgProjectPrefillUrlBuilder::FRAGMENT_PREFIX,
            $opened[0],
        );
        $encoded = substr($opened[0], strpos($opened[0], '=') + 1);
        $json = base64_decode(
            strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4),
            true,
        );
        self::assertIsString($json);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $payload['version']);
        self::assertSame('Example', $payload['title']);
        self::assertSame('example', $payload['machineName']);
        self::assertSame('full', $payload['projectType']);
        self::assertSame('A short summary.', $payload['summary']);
        self::assertStringContainsString('<p>Generated description.</p>', $payload['descriptionHtml']);
        self::assertSame('Actively maintained', $payload['maintenanceStatus']);
        self::assertSame('Under active development', $payload['developmentStatus']);
        self::assertSame(['Site structure'], $payload['categories']);
        self::assertSame(['Views'], $payload['ecosystems']);
        self::assertSame([
            ['name' => 'Example organization', 'howTheyHelped' => 'Provides testing.'],
        ], $payload['supportingOrganizations']);
        self::assertSame(['Code', 'Documentation'], $payload['issueComponents']);
        self::assertTrue($payload['enableIssueTracker']);
        self::assertSame('https://example.org', $payload['homepage']);
        self::assertSame([
            ['title' => 'Documentation', 'url' => 'https://example.org/docs'],
        ], $payload['documentation']);
        $display = $output->fetch();
        self::assertStringContainsString('Review the prefilled form in your browser and press Save.', $display);
        self::assertStringContainsString('✓ Preparing Drupal.org project form', $display);
        self::assertStringNotContainsString('Maintenance status', $display);
        self::assertStringNotContainsString('Development status', $display);
        self::assertLessThan(
            strpos($display, 'DRUPAL.ORG METADATA'),
            strpos($display, 'CREATE PROJECT ON DRUPAL.ORG'),
        );
        self::assertGreaterThan(
            strpos($display, 'DRUPAL.ORG METADATA'),
            strpos($display, '■ Preparing Drupal.org project form...'),
        );
    }

    public function testDecliningPrefillKeepsTheManualHandoff(): void
    {
        $opened = [];
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client(),
            static function (string $url) use (&$opened): void {
                $opened[] = $url;
            },
        );
        $input = new ArrayInput(
            [],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $input->setInteractive(true);
        $input->setStream($this->inputStream("n\ncancel\n"));

        $result = $handoff->run(
            $input,
            new BufferedOutput(),
            new BufferedOutput(),
            new QuestionHelper(),
            'example',
            'Example',
            'module',
            '^11',
            $this->metadata(),
        );

        self::assertSame(ProjectCreationHandoffResult::CANCELLED, $result->status);
        self::assertSame([DrupalOrgProjectCreationHandoff::PROJECT_CREATION_URL], $opened);
    }

    public function testReopensTheSamePrefillUrl(): void
    {
        $opened = [];
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client(),
            static function (string $url) use (&$opened): void {
                $opened[] = $url;
            },
        );
        $input = new ArrayInput(
            [],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $input->setInteractive(true);
        $input->setStream($this->inputStream("y\nopen\ncancel\n"));

        $result = $handoff->run(
            $input,
            new BufferedOutput(),
            new BufferedOutput(),
            new QuestionHelper(),
            'example',
            'Example',
            'module',
            '^11',
            $this->metadata(),
        );

        self::assertSame(ProjectCreationHandoffResult::CANCELLED, $result->status);
        self::assertCount(2, $opened);
        self::assertSame($opened[0], $opened[1]);
    }

    public function testNoBrowserSkipsPrefillQuestions(): void
    {
        $opened = [];
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client(),
            static function (string $url) use (&$opened): void {
                $opened[] = $url;
            },
        );
        $input = new ArrayInput(
            ['--no-browser' => true],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $input->setInteractive(true);
        $input->setStream($this->inputStream("cancel\n"));
        $output = new BufferedOutput();

        $result = $handoff->run(
            $input,
            $output,
            $output,
            new QuestionHelper(),
            'example',
            'Example',
            'module',
            '^11',
            $this->metadata(),
        );

        self::assertSame(ProjectCreationHandoffResult::CANCELLED, $result->status);
        self::assertSame([], $opened);
        $display = $output->fetch();
        self::assertStringContainsString(DrupalOrgProjectCreationHandoff::PROJECT_CREATION_URL, $display);
        self::assertStringNotContainsString('Prefill the Drupal.org project form automatically?', $display);
    }

    public function testOversizedPrefillFallsBackToTheNormalUrl(): void
    {
        $opened = [];
        $handoff = new DrupalOrgProjectCreationHandoff(
            $this->client(),
            static function (string $url) use (&$opened): void {
                $opened[] = $url;
            },
        );
        $input = new ArrayInput(
            [],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $input->setInteractive(true);
        $input->setStream($this->inputStream("y\ncancel\n"));
        $output = new BufferedOutput();

        $result = $handoff->run(
            $input,
            $output,
            $output,
            new QuestionHelper(),
            'example',
            'Example',
            'module',
            '^11',
            $this->metadata(str_repeat('description ', 500)),
        );

        self::assertSame(ProjectCreationHandoffResult::CANCELLED, $result->status);
        self::assertSame([DrupalOrgProjectCreationHandoff::PROJECT_CREATION_URL], $opened);
        self::assertStringContainsString('Browser prefill is unavailable because the generated metadata is too large.', $output->fetch());
    }

    public function testTechnicalFailureWritesToErrorOutput(): void
    {
        $handoff = new DrupalOrgProjectCreationHandoff($this->client(new Response(500)));
        $input = new ArrayInput(
            ['--no-browser' => true],
            new InputDefinition([new InputOption('no-browser', null, InputOption::VALUE_NONE)]),
        );
        $output = new BufferedOutput();
        $errorOutput = new BufferedOutput();
        $input->setInteractive(true);
        $input->setStream($this->inputStream("\n"));

        $result = $handoff->run(
            $input,
            $output,
            $errorOutput,
            new QuestionHelper(),
            'example',
            'Example',
            'module',
            '^11',
        );

        self::assertSame(ProjectCreationHandoffResult::FAILED, $result->status);
        self::assertStringNotContainsString('Could not check Drupal.org project:', $output->fetch());
        self::assertStringContainsString('Could not check Drupal.org project:', $errorOutput->fetch());
    }

    private function client(?Response $response = null): Client
    {
        return new Client(false, HandlerStack::create(new MockHandler($response === null ? [] : [$response])));
    }

    /** @return resource */
    private function inputStream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function metadata(string $description = 'Generated description.'): ProjectMetadataResult
    {
        return new ProjectMetadataResult(
            'Example',
            'example',
            'module',
            '^11',
            ['drupal:views'],
            'A short summary.',
            $description,
            ['Create records'],
            ['Drupal Field API'],
            'provider',
            'model',
            ['input' => null, 'output' => null, 'total' => null],
            categories: ['Site structure'],
            ecosystems: ['Views'],
            supportingOrganizations: [
                ['name' => 'Example organization', 'howTheyHelped' => 'Provides testing.'],
            ],
            issues: [
                'components' => ['Code', 'Documentation'],
                'submissionGuidelines' => '',
                'summaryTemplate' => '',
                'enableTracker' => true,
            ],
            resources: [
                'homepage' => 'https://example.org',
                'changelog' => '',
                'demo' => '',
                'documentation' => [
                    ['title' => 'Documentation', 'url' => 'https://example.org/docs'],
                ],
                'screenshots' => [],
            ],
        );
    }
}
