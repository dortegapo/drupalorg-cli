<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class ModuleContributionAssessmentResolver
{
    private const INVALID_INPUT_MESSAGE = 'Module contribution assessment input is invalid.';

    /**
     * @param array<string, mixed> $moduleReview
     * @param array<string, mixed> $comparison
     *
     * @return array{
     *     status: 'candidate'|'candidate_with_changes'|'not_candidate_yet',
     *     signals: list<array{code: string, severity: 'warning'|'blocking'}>
     * }
     */
    public function resolve(array $moduleReview, array $comparison): array
    {
        $reusabilityStatus = $this->readStatus(
            $moduleReview,
            'reusability',
            ['reusable', 'needs_generalization', 'client_specific', 'unclear'],
        );
        $contribReadinessStatus = $this->readStatus(
            $moduleReview,
            'contrib_readiness',
            ['ready', 'needs_changes', 'significant_changes', 'unclear'],
        );
        $verdict = $this->readComparisonVerdict($comparison);

        $signals = [];
        if ($verdict === 'possible_overlap') {
            $signals[] = [
                'code' => 'possible_overlap',
                'severity' => 'warning',
            ];
        } elseif ($verdict === 'likely_duplicate') {
            $signals[] = [
                'code' => 'likely_duplicate',
                'severity' => 'blocking',
            ];
        }

        if ($reusabilityStatus === 'needs_generalization') {
            $signals[] = [
                'code' => 'needs_generalization',
                'severity' => 'warning',
            ];
        } elseif ($reusabilityStatus === 'client_specific') {
            $signals[] = [
                'code' => 'client_specific',
                'severity' => 'blocking',
            ];
        } elseif ($reusabilityStatus === 'unclear') {
            $signals[] = [
                'code' => 'reusability_unclear',
                'severity' => 'warning',
            ];
        }

        if ($contribReadinessStatus === 'needs_changes') {
            $signals[] = [
                'code' => 'needs_changes',
                'severity' => 'warning',
            ];
        } elseif ($contribReadinessStatus === 'significant_changes') {
            $signals[] = [
                'code' => 'significant_changes',
                'severity' => 'blocking',
            ];
        } elseif ($contribReadinessStatus === 'unclear') {
            $signals[] = [
                'code' => 'contrib_readiness_unclear',
                'severity' => 'warning',
            ];
        }

        $status = 'candidate';
        foreach ($signals as $signal) {
            if ($signal['severity'] === 'blocking') {
                $status = 'not_candidate_yet';
                break;
            }

            $status = 'candidate_with_changes';
        }

        return [
            'status' => $status,
            'signals' => $signals,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $allowedStatuses
     */
    private function readStatus(array $input, string $key, array $allowedStatuses): string
    {
        $section = $input[$key] ?? null;
        if (!is_array($section)
            || !array_key_exists('status', $section)
            || !is_string($section['status'])
            || !in_array($section['status'], $allowedStatuses, true)
        ) {
            $this->invalid();
        }

        return $section['status'];
    }

    /**
     * @param array<string, mixed> $comparison
     */
    private function readComparisonVerdict(array $comparison): string
    {
        $verdict = $comparison['verdict'] ?? null;
        $allowedVerdicts = ['no_clear_duplicate', 'possible_overlap', 'likely_duplicate'];
        if (!is_string($verdict) || !in_array($verdict, $allowedVerdicts, true)) {
            $this->invalid();
        }

        return $verdict;
    }

    private function invalid(): never
    {
        throw new \InvalidArgumentException(self::INVALID_INPUT_MESSAGE);
    }
}
