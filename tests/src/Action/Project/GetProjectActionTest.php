<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Action\Project;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Action\Project\GetProjectAction;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Result\Project\GetProjectResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetProjectAction::class)]
#[CoversClass(GetProjectResult::class)]
final class GetProjectActionTest extends TestCase
{
    public function testReturnsFoundProjectExplicitly(): void
    {
        $action = new GetProjectAction($this->client([
            'list' => [[
                'nid' => '3060',
                'title' => 'Drupal',
                'field_project_machine_name' => 'drupal',
            ]],
        ]));

        $result = $action('drupal');

        self::assertTrue($result->found());
        self::assertSame('drupal', $result->machineName);
        self::assertNotNull($result->project);
        self::assertSame('Drupal', $result->project->title);
    }

    public function testReturnsNotFoundExplicitly(): void
    {
        $result = (new GetProjectAction($this->client(['list' => []])))('missing');

        self::assertFalse($result->found());
        self::assertNull($result->project);
        self::assertSame([
            'machine_name' => 'missing',
            'found' => false,
            'project' => null,
        ], $result->jsonSerialize());
    }

    /** @param array<string, mixed> $body */
    private function client(array $body): Client
    {
        return new Client(false, HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)),
        ])));
    }
}
