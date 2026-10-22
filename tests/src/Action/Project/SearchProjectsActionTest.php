<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Action\Project;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Action\Project\SearchProjectsAction;
use mglaman\DrupalOrg\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchProjectsAction::class)]
final class SearchProjectsActionTest extends TestCase
{
    public function testSearchReturnsDrupalOrgProjects(): void
    {
        $client = new Client(handler: HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'data' => [[
                    'attributes' => [
                        'field_project_machine_name' => 'search_api',
                        'title' => 'Search API',
                        'body' => ['summary' => '<p>Search infrastructure.</p>'],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ])));

        $result = (new SearchProjectsAction($client))('semantic search', 5);

        self::assertSame('semantic search', $result->query);
        self::assertSame('search_api', $result->projects[0]['machineName']);
        self::assertSame('Search infrastructure.', $result->projects[0]['description']);
        self::assertSame(
            'https://www.drupal.org/project/search_api',
            $result->jsonSerialize()['projects'][0]['url'],
        );
    }
}
