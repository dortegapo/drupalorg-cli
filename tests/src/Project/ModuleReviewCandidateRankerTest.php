<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ModuleReviewCandidateRanker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleReviewCandidateRanker::class)]
class ModuleReviewCandidateRankerTest extends TestCase
{
    public function testAppliesPrimaryRrfFormula(): void
    {
        $results = $this->ranker()->rank([
            self::ranking('profile', [self::project('foo'), self::project('bar')]),
        ], []);

        self::assertSame('foo', $results[0]['machineName']);
        self::assertEqualsWithDelta(2 / 61, $results[0]['score'], 0.000000001);
        self::assertEqualsWithDelta(2 / 62, $results[1]['score'], 0.000000001);
    }

    public function testPrimaryWeightRanksAboveSecondaryWeight(): void
    {
        $results = $this->ranker()->rank([
            self::ranking('profile', [self::project('foo')]),
        ], [
            self::ranking('fitness', [self::project('bar')]),
        ]);

        self::assertSame(['foo', 'bar'], array_column($results, 'machineName'));
        self::assertEqualsWithDelta(2 / 61, $results[0]['score'], 0.000000001);
        self::assertEqualsWithDelta(1 / 61, $results[1]['score'], 0.000000001);
    }

    public function testRepeatedRankFifteenEvidenceBeatsOneRankOneMatch(): void
    {
        $sharedProjects = [];
        for ($rank = 1; $rank <= 15; ++$rank) {
            $sharedProjects[] = self::project($rank === 15 ? 'candidate_shared' : 'candidate_first_' . $rank);
        }
        $secondProjects = [];
        for ($rank = 1; $rank <= 15; ++$rank) {
            $secondProjects[] = self::project($rank === 15 ? 'candidate_shared' : 'candidate_second_' . $rank);
        }
        $singleProjects = [self::project('candidate_single')];

        $results = $this->ranker()->rank([
            self::ranking('first', $sharedProjects),
            self::ranking('second', $secondProjects),
            self::ranking('third', $singleProjects),
        ], []);

        self::assertSame('candidate_shared', $results[0]['machineName']);
        self::assertGreaterThan($results[1]['score'], $results[0]['score']);
        self::assertEqualsWithDelta(2 / 75 + 2 / 75, $results[0]['score'], 0.000000001);
        self::assertEqualsWithDelta(2 / 61, $results[1]['score'], 0.000000001);
    }

    public function testAccumulatesContributionsAndRecordsMatches(): void
    {
        $results = $this->ranker()->rank([
            self::ranking('profile', [self::project('foo')]),
            self::ranking('registry', [self::project('bar'), self::project('foo')]),
        ], [
            self::ranking('fitness', [self::project('bar'), self::project('bar'), self::project('foo')]),
        ]);

        self::assertSame('foo', $results[0]['machineName']);
        self::assertEqualsWithDelta(2 / 61 + 2 / 62 + 1 / 63, $results[0]['score'], 0.000000001);
        self::assertSame(
            [
                ['term' => 'profile', 'rank' => 1],
                ['term' => 'registry', 'rank' => 2],
            ],
            $results[0]['primaryMatches'],
        );
        self::assertSame(
            [
                ['term' => 'fitness', 'rank' => 3],
            ],
            $results[0]['secondaryMatches'],
        );
    }

    public function testDeduplicatesProjectsAcrossRankings(): void
    {
        $results = $this->ranker()->rank([
            self::ranking('profile', [self::project('foo')]),
        ], [
            self::ranking('fitness', [self::project('foo')]),
        ]);

        self::assertCount(1, $results);
        self::assertSame('foo', $results[0]['machineName']);
    }

    public function testCountsOnlyFirstOccurrenceWithinOneRanking(): void
    {
        $results = $this->ranker()->rank([
            self::ranking('profile', [
                self::project('foo'),
                self::project('bar'),
                self::project('foo'),
            ]),
        ], []);

        self::assertSame('foo', $results[0]['machineName']);
        self::assertEqualsWithDelta(2 / 61, $results[0]['score'], 0.000000001);
        self::assertSame(
            [['term' => 'profile', 'rank' => 1]],
            $results[0]['primaryMatches'],
        );
    }

    public function testKeepsMetadataFromFirstGlobalAppearance(): void
    {
        $results = $this->ranker()->rank([
            self::ranking('profile', [self::project('foo', 'First title', 'First description', 'first URL')]),
        ], [
            self::ranking('fitness', [self::project('foo', 'Changed title', 'Changed description', 'changed URL')]),
        ]);

        self::assertSame(
            [
                'machineName' => 'foo',
                'title' => 'First title',
                'description' => 'First description',
                'url' => 'first URL',
            ],
            array_intersect_key(
                $results[0],
                array_flip(['machineName', 'title', 'description', 'url']),
            ),
        );
    }

    public function testUsesFirstSeenForExactScoreTies(): void
    {
        $results = $this->ranker()->rank([
            self::ranking('first', [self::project('zeta')]),
            self::ranking('second', [self::project('alpha')]),
        ], []);

        self::assertSame(['zeta', 'alpha'], array_column($results, 'machineName'));
        self::assertEquals($results[0]['score'], $results[1]['score']);
    }

    public function testPreservesMatchProcessingOrder(): void
    {
        $results = $this->ranker()->rank([
            self::ranking('registry', [self::project('foo')]),
            self::ranking('profile', [self::project('foo')]),
        ], []);

        self::assertSame(
            [
                ['term' => 'registry', 'rank' => 1],
                ['term' => 'profile', 'rank' => 1],
            ],
            $results[0]['primaryMatches'],
        );
    }

    public function testAppliesLimit(): void
    {
        $results = $this->ranker()->rank([
            self::ranking('profile', [self::project('foo'), self::project('bar'), self::project('baz')]),
        ], [], 2);

        self::assertCount(2, $results);
    }

    #[DataProvider('invalidLimitProvider')]
    public function testRejectsInvalidLimit(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Module review candidate ranking limit must be greater than zero.');

        $this->ranker()->rank([], [], $limit);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidLimitProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testAllowsEmptyRankings(): void
    {
        self::assertSame([], $this->ranker()->rank([], []));
        self::assertSame(
            [],
            $this->ranker()->rank([
                self::ranking('profile', []),
            ], []),
        );
    }

    public function testAllowsSecondaryOnlyRankings(): void
    {
        $results = $this->ranker()->rank([], [
            self::ranking('fitness', [self::project('foo')]),
        ]);

        self::assertSame('foo', $results[0]['machineName']);
        self::assertEqualsWithDelta(1 / 61, $results[0]['score'], 0.000000001);
    }

    public function testPreservesOriginalTermInMatch(): void
    {
        $results = $this->ranker()->rank([
            self::ranking(' Profile ', [self::project('foo')]),
        ], []);

        self::assertSame([['term' => ' Profile ', 'rank' => 1]], $results[0]['primaryMatches']);
    }

    #[DataProvider('invalidStructureProvider')]
    public function testRejectsInvalidStructure(string $case): void
    {
        $primaryRankings = [self::ranking('profile', [self::project('foo')])];
        $secondaryRankings = [];

        switch ($case) {
            case 'empty term':
                $primaryRankings[0]['term'] = '  ';
                break;
            case 'projects not array':
                $primaryRankings[0]['projects'] = 'invalid';
                break;
            case 'project not array':
                $primaryRankings[0]['projects'] = ['invalid'];
                break;
            case 'machine name empty':
                $primaryRankings[0]['projects'][0]['machineName'] = '  ';
                break;
            case 'title not string':
                $primaryRankings[0]['projects'][0]['title'] = 123;
                break;
            case 'description not string':
                $primaryRankings[0]['projects'][0]['description'] = 123;
                break;
            case 'url not string':
                $primaryRankings[0]['projects'][0]['url'] = 123;
                break;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Module review candidate ranking input is invalid.');
        $this->ranker()->rank($primaryRankings, $secondaryRankings);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStructureProvider(): iterable
    {
        yield 'empty term' => ['empty term'];
        yield 'projects not array' => ['projects not array'];
        yield 'project not array' => ['project not array'];
        yield 'machine name empty' => ['machine name empty'];
        yield 'title not string' => ['title not string'];
        yield 'description not string' => ['description not string'];
        yield 'url not string' => ['url not string'];
    }

    /**
     * @param list<array{machineName: string, title: string, description: string, url: string}> $projects
     *
     * @return array{term: string, projects: list<array{machineName: string, title: string, description: string, url: string}>}
     */
    private static function ranking(string $term, array $projects): array
    {
        return [
            'term' => $term,
            'projects' => $projects,
        ];
    }

    /**
     * @return array{machineName: string, title: string, description: string, url: string}
     */
    private static function project(
        string $machineName,
        string $title = 'Title',
        string $description = 'Description',
        string $url = 'https://www.drupal.org/project/example',
    ): array {
        return [
            'machineName' => $machineName,
            'title' => $title,
            'description' => $description,
            'url' => $url,
        ];
    }

    private function ranker(): ModuleReviewCandidateRanker
    {
        return new ModuleReviewCandidateRanker();
    }
}
