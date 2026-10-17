<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class ModuleReviewComparisonResultValidator
{
    private const INVALID_CANDIDATE_POOL_MESSAGE = 'Module review comparison candidate pool is invalid.';

    private const INVALID_RESULT_MESSAGE = 'Drupal AI module comparison returned an invalid result.';

    private const MAXIMUM_OUTPUT_CANDIDATES = 8;

    /**
     * @param array<string, mixed> $result
     * @param list<array{
     *     machineName: string,
     *     title: string,
     *     description: string,
     *     url: string,
     *     score: float,
     *     primaryMatches: list<array{term: string, rank: int}>,
     *     secondaryMatches: list<array{term: string, rank: int}>
     * }> $candidates
     *
     * @return array{
     *     verdict: 'no_clear_duplicate'|'possible_overlap'|'likely_duplicate',
     *     confidence: int|float,
     *     summary: string,
     *     candidates: list<array{
     *         machine_name: string,
     *         assessment: 'likely_duplicate'|'possible_overlap'|'related_not_duplicate',
     *         overlap: list<string>,
     *         confirmed_differences: list<string>,
     *         unverified_details: list<string>,
     *         reason: string
     *     }>}
     */
    public function validate(array $result, array $candidates): array
    {
        $candidatePool = $this->validateCandidatePool($candidates);
        $verdict = $this->validateVerdict($result['verdict'] ?? null);
        $confidence = $this->validateConfidence($result['confidence'] ?? null);
        $summary = $this->requiredResultString($result['summary'] ?? null);
        $outputCandidates = $this->validateOutputCandidates(
            $result['candidates'] ?? null,
            $candidatePool,
        );

        if ($verdict !== $this->deriveVerdict($outputCandidates)) {
            $this->invalidResult();
        }

        return [
            'verdict' => $verdict,
            'confidence' => $confidence,
            'summary' => $summary,
            'candidates' => $outputCandidates,
        ];
    }

    /**
     * @param list<mixed> $candidates
     *
     * @return array<string, true>
     */
    private function validateCandidatePool(array $candidates): array
    {
        if (!array_is_list($candidates)) {
            $this->invalidCandidatePool();
        }

        $machineNames = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)
                || !array_key_exists('machineName', $candidate)
                || !is_string($candidate['machineName'])
                || $candidate['machineName'] === ''
                || trim($candidate['machineName']) !== $candidate['machineName']
                || isset($machineNames[$candidate['machineName']])
            ) {
                $this->invalidCandidatePool();
            }

            $machineNames[$candidate['machineName']] = true;
        }

        return $machineNames;
    }

    /**
     * @return 'no_clear_duplicate'|'possible_overlap'|'likely_duplicate'
     */
    private function validateVerdict(mixed $value): string
    {
        if (!is_string($value) || !in_array(
            $value,
            ['no_clear_duplicate', 'possible_overlap', 'likely_duplicate'],
            true,
        )) {
            $this->invalidResult();
        }

        return $value;
    }

    private function validateConfidence(mixed $value): int|float
    {
        if ((!is_int($value) && !is_float($value))
            || !is_finite((float) $value)
            || $value < 0
            || $value > 1
        ) {
            $this->invalidResult();
        }

        return $value;
    }

    /**
     * @param array<string, true> $candidatePool
     *
     * @return list<array{
     *     machine_name: string,
     *     assessment: 'likely_duplicate'|'possible_overlap'|'related_not_duplicate',
     *     overlap: list<string>,
     *     confirmed_differences: list<string>,
     *     unverified_details: list<string>,
     *     reason: string
     * }>
     */
    private function validateOutputCandidates(mixed $value, array $candidatePool): array
    {
        if (!is_array($value)
            || !array_is_list($value)
            || count($value) > self::MAXIMUM_OUTPUT_CANDIDATES
        ) {
            $this->invalidResult();
        }

        $validated = [];
        $seen = [];
        foreach ($value as $candidate) {
            if (!is_array($candidate)) {
                $this->invalidResult();
            }

            $machineName = $this->validateMachineName($candidate['machine_name'] ?? null, $candidatePool);
            if (isset($seen[$machineName])) {
                $this->invalidResult();
            }

            $assessment = $this->validateAssessment($candidate['assessment'] ?? null);
            $overlap = $this->validateTextList($candidate['overlap'] ?? null);
            if ($assessment !== 'related_not_duplicate' && $overlap === []) {
                $this->invalidResult();
            }

            $seen[$machineName] = true;
            $validated[] = [
                'machine_name' => $machineName,
                'assessment' => $assessment,
                'overlap' => $overlap,
                'confirmed_differences' => $this->validateTextList($candidate['confirmed_differences'] ?? null),
                'unverified_details' => $this->validateTextList($candidate['unverified_details'] ?? null),
                'reason' => $this->requiredResultString($candidate['reason'] ?? null),
            ];
        }

        return $validated;
    }

    /**
     * @param array<string, true> $candidatePool
     */
    private function validateMachineName(mixed $value, array $candidatePool): string
    {
        if (!is_string($value)
            || $value === ''
            || trim($value) !== $value
            || !isset($candidatePool[$value])
        ) {
            $this->invalidResult();
        }

        return $value;
    }

    /**
     * @return 'likely_duplicate'|'possible_overlap'|'related_not_duplicate'
     */
    private function validateAssessment(mixed $value): string
    {
        if (!is_string($value) || !in_array(
            $value,
            ['likely_duplicate', 'possible_overlap', 'related_not_duplicate'],
            true,
        )) {
            $this->invalidResult();
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function validateTextList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalidResult();
        }

        $validated = [];
        foreach ($value as $item) {
            $validated[] = $this->requiredResultString($item);
        }

        return $validated;
    }

    private function requiredResultString(mixed $value): string
    {
        if (!is_string($value)) {
            $this->invalidResult();
        }

        $value = trim($value);
        if ($value === '') {
            $this->invalidResult();
        }

        return $value;
    }

    /**
     * @param list<array{assessment: string}> $candidates
     *
     * @return 'no_clear_duplicate'|'possible_overlap'|'likely_duplicate'
     */
    private function deriveVerdict(array $candidates): string
    {
        $hasPossibleOverlap = false;
        foreach ($candidates as $candidate) {
            if ($candidate['assessment'] === 'likely_duplicate') {
                return 'likely_duplicate';
            }
            if ($candidate['assessment'] === 'possible_overlap') {
                $hasPossibleOverlap = true;
            }
        }

        return $hasPossibleOverlap ? 'possible_overlap' : 'no_clear_duplicate';
    }

    private function invalidCandidatePool(): never
    {
        throw new \InvalidArgumentException(self::INVALID_CANDIDATE_POOL_MESSAGE);
    }

    private function invalidResult(): never
    {
        throw new \RuntimeException(self::INVALID_RESULT_MESSAGE);
    }
}
