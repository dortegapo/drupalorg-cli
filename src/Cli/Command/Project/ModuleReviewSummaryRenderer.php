<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use Symfony\Component\Console\Output\OutputInterface;

final class ModuleReviewSummaryRenderer
{
    public function __construct(
        private readonly OutputInterface $output,
    ) {
    }

    /**
     * @param array<string, mixed> $result
     */
    public function render(array $result, ?string $pdfPath = null): void
    {
        $this->output->writeln('');
        $this->output->writeln($this->sectionHeading('REVIEW RESULT'));

        if (($result['complete'] ?? false) !== true) {
            $limitation = $result['limitations'][0] ?? [];
            $stage = is_array($limitation) && is_string($limitation['stage'] ?? null)
                ? $limitation['stage']
                : 'unknown';
            $message = is_array($limitation) && is_string($limitation['message'] ?? null)
                ? $limitation['message']
                : 'The AI module review could not be completed.';
            $this->output->writeln($this->styleStatus('REVIEW INCOMPLETE', 'yellow'));
            $this->output->writeln('The AI module review could not be completed.');
            $this->output->writeln(sprintf('Stage: %s', $stage));
            $this->output->writeln($message);
            $this->output->writeln('Publication can continue without it.');
            $this->writePdfPath($pdfPath);
            return;
        }

        $assessment = is_array($result['assessment'] ?? null) ? $result['assessment'] : [];
        $assessmentStatus = is_string($assessment['status'] ?? null) ? $assessment['status'] : 'unknown';
        $assessmentLabels = [
            'candidate' => 'CANDIDATE',
            'candidate_with_changes' => 'CANDIDATE WITH CHANGES',
            'not_candidate_yet' => 'NOT A CANDIDATE YET',
        ];
        $this->writeStatusLine(
            'Assessment',
            $assessmentLabels[$assessmentStatus] ?? strtoupper($assessmentStatus),
            match ($assessmentStatus) {
                'candidate' => 'green',
                'candidate_with_changes' => 'yellow',
                'not_candidate_yet' => 'red',
                default => 'yellow',
            },
        );

        $review = is_array($result['review'] ?? null) ? $result['review'] : [];
        $reusability = is_array($review['reusability'] ?? null) ? $review['reusability'] : [];
        $readiness = is_array($review['contrib_readiness'] ?? null) ? $review['contrib_readiness'] : [];
        $reusabilityStatus = is_string($reusability['status'] ?? null) ? $reusability['status'] : 'unknown';
        $readinessStatus = is_string($readiness['status'] ?? null) ? $readiness['status'] : 'unknown';
        $this->writeStatusLine(
            'Reusability',
            $this->humanizeReusabilityLabel($reusabilityStatus),
            match ($reusabilityStatus) {
                'reusable' => 'green',
                'client_specific' => 'red',
                default => 'yellow',
            },
        );
        $this->writeStatusLine(
            'Contrib readiness',
            $this->humanizeReadinessLabel($readinessStatus),
            match ($readinessStatus) {
                'ready' => 'green',
                'significant_changes' => 'red',
                default => 'yellow',
            },
        );

        $comparison = is_array($result['comparison'] ?? null) ? $result['comparison'] : [];
        $overlapStatus = is_string($comparison['verdict'] ?? null) ? $comparison['verdict'] : 'unknown';
        $this->writeStatusLine(
            'Drupal.org overlap',
            $this->humanizeOverlapLabel($overlapStatus),
            match ($overlapStatus) {
                'no_clear_duplicate' => 'green',
                'likely_duplicate' => 'red',
                default => 'yellow',
            },
        );

        if ($this->output->isVerbose()) {
            $this->writeVerboseDiagnostics($result);
        }

        $this->writePotentialOverlaps(
            $comparison,
            is_array($result['candidates'] ?? null) ? $result['candidates'] : [],
        );
        $this->writePdfPath($pdfPath);
    }

    private function sectionHeading(string $title): string
    {
        return '<fg=cyan;options=bold>' . $title . '</>';
    }

    private function styleStatus(string $value, string $color): string
    {
        return sprintf('<fg=%s;options=bold>%s</>', $color, $value);
    }

    private function writeStatusLine(string $label, string $value, string $color): void
    {
        $this->output->writeln(sprintf(
            "%-20s %s",
            $label,
            $this->styleStatus($value, $color),
        ));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function writeVerboseDiagnostics(array $result): void
    {
        $this->output->writeln('');
        $this->output->writeln('<comment>Review details</comment>');
        $source = is_array($result['source'] ?? null) ? $result['source'] : [];
        $coverage = is_array($source['coverage'] ?? null) ? $source['coverage'] : [];
        $coverageMode = ($coverage['mode'] ?? null) === 'selected' ? 'partial' : 'full';
        $candidates = is_array($result['candidates'] ?? null) ? $result['candidates'] : [];
        $redactions = is_array($source['redactions'] ?? null) ? $source['redactions'] : [];
        $this->output->writeln('Source coverage: ' . $coverageMode);
        $this->output->writeln('Candidates evaluated: ' . count($candidates));
        $this->output->writeln('Local redactions: ' . count($redactions));
        $this->writeAiMetadata('AI #1', $result['ai']['review'] ?? null);
        $this->writeAiMetadata('AI #2', $result['ai']['comparison'] ?? null);

        $tokenUsage = $this->sumTokenUsage($result['ai'] ?? null);
        if ($tokenUsage === null) {
            $this->output->writeln('Tokens: unavailable');
        } else {
            $this->output->writeln(sprintf(
                'Tokens: input %s · output %s · total %s',
                $this->formatTokenValue($tokenUsage['input']),
                $this->formatTokenValue($tokenUsage['output']),
                $this->formatTokenValue($tokenUsage['total']),
            ));
        }
    }

    private function writePdfPath(?string $pdfPath): void
    {
        if ($pdfPath !== null) {
            $this->output->writeln('');
            $this->output->writeln(sprintf('Full report: %s', $pdfPath));
        }
    }

    /**
     * @param mixed $metadata
     */
    private function writeAiMetadata(string $label, mixed $metadata): void
    {
        if (!is_array($metadata)) {
            return;
        }

        $provider = is_string($metadata['providerId'] ?? null) ? $metadata['providerId'] : 'unknown';
        $model = is_string($metadata['modelId'] ?? null) ? $metadata['modelId'] : 'unknown';
        $this->output->writeln(sprintf('%s: %s / %s', $label, $provider, $model));
    }

    /**
     * @param mixed $ai
     *
     * @return array{input: ?int, output: ?int, total: ?int}|null
     */
    private function sumTokenUsage(mixed $ai): ?array
    {
        if (!is_array($ai)) {
            return null;
        }

        $totals = ['input' => null, 'output' => null, 'total' => null];
        $found = false;
        foreach (['review', 'comparison'] as $stage) {
            $usage = $ai[$stage]['tokenUsage'] ?? null;
            if (!is_array($usage)) {
                continue;
            }
            foreach (array_keys($totals) as $key) {
                if (is_int($usage[$key] ?? null)) {
                    $totals[$key] = ($totals[$key] ?? 0) + $usage[$key];
                    $found = true;
                }
            }
        }

        return $found ? $totals : null;
    }

    private function formatTokenValue(?int $value): string
    {
        return $value === null ? 'unavailable' : (string) $value;
    }

    /**
     * @param array<string, mixed> $comparison
     * @param list<array<string, mixed>> $candidates
     */
    private function writePotentialOverlaps(
        array $comparison,
        array $candidates,
    ): void {
        if (!array_key_exists('candidates', $comparison) || !is_array($comparison['candidates'])) {
            return;
        }
        $matches = $comparison['candidates'];
        if ($matches === []) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln('<fg=cyan;options=bold>Potential overlaps</>');
        $candidateTitles = [];
        foreach ($candidates as $candidate) {
            if (is_string($candidate['machineName'] ?? null)) {
                $candidateTitles[$candidate['machineName']] = is_string($candidate['title'] ?? null)
                    && trim($candidate['title']) !== ''
                    ? $candidate['title']
                    : $candidate['machineName'];
            }
        }

        $shown = 0;
        foreach ($matches as $match) {
            if (!is_array($match) || !is_string($match['machine_name'] ?? null)) {
                continue;
            }

            $assessment = is_string($match['assessment'] ?? null) ? $match['assessment'] : 'unknown';
            if (!in_array($assessment, ['likely_duplicate', 'possible_overlap'], true)) {
                continue;
            }

            $machineName = $match['machine_name'];
            $title = $candidateTitles[$machineName] ?? $machineName;
            $assessmentLabel = $this->humanizeReviewLabel($assessment);
            $assessmentColor = $assessment === 'likely_duplicate' ? 'red' : 'yellow';
            $this->output->writeln(sprintf(
                '  %-18s %s',
                $title . ' (' . $machineName . ')',
                $this->styleStatus($assessmentLabel, $assessmentColor),
            ));
            ++$shown;
            if ($shown === ($this->output->isVerbose() ? 5 : 3)) {
                break;
            }
        }
    }

    private function humanizeReviewLabel(string $value): string
    {
        return match ($value) {
            'likely_duplicate' => 'LIKELY DUPLICATE',
            'possible_overlap' => 'POSSIBLE OVERLAP',
            default => 'UNKNOWN',
        };
    }

    private function humanizeOverlapLabel(string $value): string
    {
        return match ($value) {
            'likely_duplicate' => 'LIKELY DUPLICATE',
            'possible_overlap' => 'POSSIBLE OVERLAP',
            'no_clear_duplicate' => 'NO CLEAR DUPLICATE',
            default => 'UNKNOWN',
        };
    }

    private function humanizeReusabilityLabel(string $value): string
    {
        return match ($value) {
            'reusable' => 'REUSABLE',
            'needs_generalization' => 'NEEDS GENERALIZATION',
            'client_specific' => 'CLIENT SPECIFIC',
            'unclear' => 'UNCLEAR',
            default => 'UNKNOWN',
        };
    }

    private function humanizeReadinessLabel(string $value): string
    {
        return match ($value) {
            'ready' => 'READY',
            'needs_changes' => 'NEEDS CHANGES',
            'significant_changes' => 'SIGNIFICANT CHANGES',
            'unclear' => 'UNCLEAR',
            default => 'UNKNOWN',
        };
    }
}
