<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class ModuleReviewCandidateRanker
{
    private const RRF_K = 60;

    private const PRIMARY_WEIGHT = 2.0;

    private const SECONDARY_WEIGHT = 1.0;

    private const INVALID_INPUT_MESSAGE = 'Module review candidate ranking input is invalid.';

    /**
     * @param list<array{
     *     term: string,
     *     projects: list<array{
     *         machineName: string,
     *         title: string,
     *         description: string,
     *         url: string
     *     }>
     * }> $primaryRankings
     *
     * @param list<array{
     *     term: string,
     *     projects: list<array{
     *         machineName: string,
     *         title: string,
     *         description: string,
     *         url: string
     *     }>
     * }> $secondaryRankings
     *
     * @return list<array{
     *     machineName: string,
     *     title: string,
     *     description: string,
     *     url: string,
     *     score: float,
     *     primaryMatches: list<array{term: string, rank: int}>,
     *     secondaryMatches: list<array{term: string, rank: int}>
     * }>
     */
    public function rank(array $primaryRankings, array $secondaryRankings, int $limit = 10): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException(
                'Module review candidate ranking limit must be greater than zero.',
            );
        }

        /**
         * @var array<string, array{
         *     machineName: string,
         *     title: string,
         *     description: string,
         *     url: string,
         *     score: float,
         *     primaryMatches: list<array{term: string, rank: int}>,
         *     secondaryMatches: list<array{term: string, rank: int}>,
         *     firstSeen: int
         * }> $candidates
         */
        $candidates = [];
        $firstSeen = 0;

        $this->processRankings(
            $primaryRankings,
            self::PRIMARY_WEIGHT,
            true,
            $candidates,
            $firstSeen,
        );
        $this->processRankings(
            $secondaryRankings,
            self::SECONDARY_WEIGHT,
            false,
            $candidates,
            $firstSeen,
        );

        usort($candidates, static function (array $left, array $right): int {
            if ($left['score'] === $right['score']) {
                return $left['firstSeen'] <=> $right['firstSeen'];
            }

            return $left['score'] > $right['score'] ? -1 : 1;
        });

        $results = [];
        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $results[] = [
                'machineName' => $candidate['machineName'],
                'title' => $candidate['title'],
                'description' => $candidate['description'],
                'url' => $candidate['url'],
                'score' => $candidate['score'],
                'primaryMatches' => $candidate['primaryMatches'],
                'secondaryMatches' => $candidate['secondaryMatches'],
            ];
        }

        return $results;
    }

    /**
     * @param mixed $rankings
     * @param array<string, array{
     *     machineName: string,
     *     title: string,
     *     description: string,
     *     url: string,
     *     score: float,
     *     primaryMatches: list<array{term: string, rank: int}>,
     *     secondaryMatches: list<array{term: string, rank: int}>,
     *     firstSeen: int
     * }> $candidates
     */
    private function processRankings(
        mixed $rankings,
        float $weight,
        bool $primary,
        array &$candidates,
        int &$firstSeen,
    ): void {
        if (!is_array($rankings)) {
            $this->invalid();
        }

        foreach ($rankings as $ranking) {
            if (!is_array($ranking)
                || !array_key_exists('term', $ranking)
                || !is_string($ranking['term'])
                || trim($ranking['term']) === ''
                || !array_key_exists('projects', $ranking)
                || !is_array($ranking['projects'])
            ) {
                $this->invalid();
            }

            $term = $ranking['term'];
            $seenInRanking = [];
            $rank = 0;
            foreach ($ranking['projects'] as $project) {
                $rank++;
                if (!is_array($project)
                    || !array_key_exists('machineName', $project)
                    || !is_string($project['machineName'])
                    || trim($project['machineName']) === ''
                    || !array_key_exists('title', $project)
                    || !is_string($project['title'])
                    || !array_key_exists('description', $project)
                    || !is_string($project['description'])
                    || !array_key_exists('url', $project)
                    || !is_string($project['url'])
                ) {
                    $this->invalid();
                }

                $machineName = $project['machineName'];
                if (isset($seenInRanking[$machineName])) {
                    continue;
                }

                $seenInRanking[$machineName] = true;
                if (!isset($candidates[$machineName])) {
                    $candidates[$machineName] = [
                        'machineName' => $machineName,
                        'title' => $project['title'],
                        'description' => $project['description'],
                        'url' => $project['url'],
                        'score' => 0.0,
                        'primaryMatches' => [],
                        'secondaryMatches' => [],
                        'firstSeen' => $firstSeen++,
                    ];
                }

                $candidates[$machineName]['score'] += $weight / (self::RRF_K + $rank);
                $match = [
                    'term' => $term,
                    'rank' => $rank,
                ];
                if ($primary) {
                    $candidates[$machineName]['primaryMatches'][] = $match;
                } else {
                    $candidates[$machineName]['secondaryMatches'][] = $match;
                }
            }
        }
    }

    private function invalid(): never
    {
        throw new \InvalidArgumentException(self::INVALID_INPUT_MESSAGE);
    }
}
