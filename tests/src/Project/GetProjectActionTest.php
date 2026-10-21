<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Action\Project\GetProjectAction;
use mglaman\DrupalOrg\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetProjectAction::class)]
final class GetProjectActionTest extends TestCase
{
    public function testReturnsProject(): void
    {
        $client = $this->client(new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['list' => [[
                'nid' => '1',
                'title' => 'Example',
                'field_project_machine_name' => 'example',
            ]]], JSON_THROW_ON_ERROR),
        ));

        $project = (new GetProjectAction($client))('example');

        self::assertNotNull($project);
        self::assertSame('example', $project->machineName);
    }

    public function testConvertsNotFoundResponseToNull(): void
    {
        $client = $this->client(new Response(404));

        self::assertNull((new GetProjectAction($client))('missing'));
    }

    public function testRethrowsTechnicalResponseErrors(): void
    {
        $client = $this->client(new Response(500, [], 'Server error'));

        $this->expectException(\Exception::class);
        (new GetProjectAction($client))('example');
    }

    private function client(Response $response): Client
    {
        return new Client(false, HandlerStack::create(new MockHandler([$response])));
    }
}
