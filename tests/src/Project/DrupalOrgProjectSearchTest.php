<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Project\DrupalOrgProjectSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(DrupalOrgProjectSearch::class)]
class DrupalOrgProjectSearchTest extends TestCase
{
    /**
     * @var array<int, array{request: RequestInterface}>
     */
    private array $history = [];

    private MockHandler $responses;

    public function testSendsExpectedQueryWithoutSort(): void
    {
        $search = $this->search(self::json(['data' => []]));

        $search->search('  token  ');

        $request = $this->sentRequest();
        parse_str($request->getUri()->getQuery(), $query);

        self::assertSame('1', $query['filter']['status']);
        self::assertSame('project_module', $query['filter']['type']);
        self::assertSame('full', $query['filter']['project_type']);
        self::assertSame('token', $query['filter']['fulltext']);
        self::assertSame('-search_api_relevance', $query['sort']);
        self::assertSame('10', $query['page']['limit']);
        self::assertSame('0', $query['page']['offset']);
        self::assertArrayNotHasKey('n_security_coverage', $query['filter']);
    }

    public function testSendsJsonApiAcceptHeader(): void
    {
        $search = $this->search(self::json(['data' => []]));

        $search->search('token');

        self::assertSame('application/vnd.api+json', $this->sentRequest()->getHeaderLine('Accept'));
    }

    public function testNormalizesProjects(): void
    {
        $search = $this->search(self::json([
            'data' => [
                [
                    'attributes' => [
                        'title' => 'Token',
                        'field_project_machine_name' => 'token',
                        'body' => [
                            'summary' => 'Provides placeholder variables.',
                            'value' => '<p>Long body.</p>',
                        ],
                    ],
                ],
                [
                    'attributes' => [
                        'title' => 'Token Filter',
                        'field_project_machine_name' => 'token_filter',
                        'body' => [
                            'summary' => 'Filters using tokens.',
                            'value' => '',
                        ],
                    ],
                ],
            ],
        ]));

        self::assertSame(
            [
                [
                    'machineName' => 'token',
                    'title' => 'Token',
                    'description' => 'Provides placeholder variables.',
                    'url' => 'https://www.drupal.org/project/token',
                ],
                [
                    'machineName' => 'token_filter',
                    'title' => 'Token Filter',
                    'description' => 'Filters using tokens.',
                    'url' => 'https://www.drupal.org/project/token_filter',
                ],
            ],
            $search->search('token')
        );
    }

    public function testPreservesApiOrder(): void
    {
        $search = $this->search(self::json([
            'data' => [
                self::project('zeta', 'Zeta'),
                self::project('alpha', 'Alpha'),
                self::project('middle', 'Middle'),
            ],
        ]));

        $projects = $search->search('token');

        self::assertSame(['zeta', 'alpha', 'middle'], array_column($projects, 'machineName'));
    }

    public function testNormalizesHtmlSummaryBeforeBodyValue(): void
    {
        $search = $this->search(self::json([
            'data' => [
                [
                    'attributes' => [
                        'title' => 'Customer records',
                        'field_project_machine_name' => 'customer_records',
                        'body' => [
                            'summary' => '<p>Provides <strong>client records</strong> &amp; profiles.</p>',
                            'value' => '<p>A completely different long body</p>',
                        ],
                    ],
                ],
            ],
        ]));

        self::assertSame(
            'Provides client records & profiles.',
            $search->search('customer')[0]['description']
        );
    }

    public function testNormalizesSummaryWhitespace(): void
    {
        $search = $this->search(self::json([
            'data' => [
                [
                    'attributes' => [
                        'title' => 'Client profile',
                        'field_project_machine_name' => 'client_profile',
                        'body' => [
                            'summary' => "  Client   profile\n management  ",
                        ],
                    ],
                ],
            ],
        ]));

        self::assertSame(
            'Client profile management',
            $search->search('client')[0]['description']
        );
    }

    public function testFallsBackToCleanBodyValue(): void
    {
        $search = $this->search(self::json([
            'data' => [
                [
                    'attributes' => [
                        'title' => 'Customer records',
                        'field_project_machine_name' => 'customer_records',
                        'body' => [
                            'summary' => '',
                            'value' => '<p>Provides <strong>customer records</strong> &amp; profiles.</p>',
                        ],
                    ],
                ],
            ],
        ]));

        self::assertSame(
            'Provides customer records & profiles.',
            $search->search('customer')[0]['description']
        );
    }

    public function testAllowsMissingDescription(): void
    {
        $search = $this->search(self::json([
            'data' => [
                [
                    'attributes' => [
                        'title' => 'No description',
                        'field_project_machine_name' => 'no_description',
                        'body' => null,
                    ],
                ],
                [
                    'attributes' => [
                        'title' => 'Missing description',
                        'field_project_machine_name' => 'missing_description',
                    ],
                ],
            ],
        ]));

        self::assertSame(
            ['', ''],
            array_column($search->search('description'), 'description')
        );
    }

    public function testTrimsMachineNameAndTitle(): void
    {
        $search = $this->search(self::json([
            'data' => [
                [
                    'attributes' => [
                        'title' => '  Token  ',
                        'field_project_machine_name' => ' token ',
                        'body' => [
                            'summary' => 'Description',
                        ],
                    ],
                ],
            ],
        ]));

        self::assertSame(
            [
                [
                    'machineName' => 'token',
                    'title' => 'Token',
                    'description' => 'Description',
                    'url' => 'https://www.drupal.org/project/token',
                ],
            ],
            $search->search('token')
        );
    }

    public function testRejectsEmptyQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Drupal.org project search query cannot be empty.');

        $this->search()->search('   ');
    }

    public function testRejectsZeroLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Drupal.org project search limit must be greater than zero.');

        $this->search()->search('token', 0);
    }

    public function testRejectsNegativeOffset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Drupal.org project search offset cannot be negative.');

        $this->search()->search('token', 10, -1);
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal.org project search returned an invalid response.');

        $this->search(new Response(200, [], '{broken'))->search('token');
    }

    public function testRejectsInvalidRoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal.org project search returned an invalid response.');

        $this->search(self::json([]))->search('token');
    }

    public function testRejectsNonArrayData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal.org project search returned an invalid response.');

        $this->search(self::json(['data' => 'wrong']))->search('token');
    }

    public function testRejectsInvalidItem(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal.org project search returned an invalid response.');

        $this->search(self::json([
            'data' => [
                [
                    'attributes' => [
                        'title' => 'Broken',
                    ],
                ],
            ],
        ]))->search('token');
    }

    /**
     * @param Response ...$responses
     */
    private function search(Response ...$responses): DrupalOrgProjectSearch
    {
        $this->responses = new MockHandler($responses);
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->history));

        return new DrupalOrgProjectSearch(new Client(false, $stack));
    }

    private function sentRequest(int $index = 0): RequestInterface
    {
        self::assertArrayHasKey($index, $this->history, sprintf('Expected request %d to have been sent.', $index));

        return $this->history[$index]['request'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/vnd.api+json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @return array{attributes: array<string, string>}
     */
    private static function project(string $machineName, string $title): array
    {
        return [
            'attributes' => [
                'title' => $title,
                'field_project_machine_name' => $machineName,
            ],
        ];
    }
}
