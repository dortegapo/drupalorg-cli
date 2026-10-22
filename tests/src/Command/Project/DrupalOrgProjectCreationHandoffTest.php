<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrgCli\Command\Project\DrupalOrgProjectCreationHandoff;
use mglaman\DrupalOrgCli\Command\Project\ProjectCreationHandoffResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

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
}
