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
        $this->output->writeln('AI-assisted contribution review');

        if (($result['complete'] ?? false) !== true) {
            $limitation = $result['limitations'][0] ?? [];
            $stage = is_array($limitation) && is_string($limitation['stage'] ?? null)
                ? $limitation['stage']
                : 'unknown';
            $message = is_array($limitation) && is_string($limitation['message'] ?? null)
                ? $limitation['message']
                : 'The AI module review could not be completed.';
            $this->output->writeln('AI module review could not be completed.');
            $this->output->writeln(sprintf('Stage: %s', $stage));
            $this->output->writeln($message);
            $this->output->writeln('The publication flow can continue without the AI review.');
            $this->writePdfPath($pdfPath);
            return;
        }

        $assessment = is_array($result['assessment'] ?? null) ? $result['assessment'] : [];
        $assessmentStatus = is_string($assessment['status'] ?? null) ? $assessment['status'] : 'unknown';
        $assessmentLabels = [
            'candidate' => 'Candidate for contribution',
            'candidate_with_changes' => 'Candidate with changes',
            'not_candidate_yet' => 'Not a candidate yet',
        ];
        $this->output->writeln(sprintf(
            'Assessment: %s',
            $assessmentLabels[$assessmentStatus] ?? $assessmentStatus,
        ));

        $review = is_array($result['review'] ?? null) ? $result['review'] : [];
        $reusability = is_array($review['reusability'] ?? null) ? $review['reusability'] : [];
        $readiness = is_array($review['contrib_readiness'] ?? null) ? $review['contrib_readiness'] : [];
        $this->output->writeln(sprintf(
            'Reusability: %s',
            $this->humanizeReusabilityLabel(is_string($reusability['status'] ?? null) ? $reusability['status'] : 'unknown'),
        ));
        $this->output->writeln(sprintf(
            'Contrib readiness: %s',
            $this->humanizeReadinessLabel(is_string($readiness['status'] ?? null) ? $readiness['status'] : 'unknown'),
        ));

        $comparison = is_array($result['comparison'] ?? null) ? $result['comparison'] : [];
        $this->output->writeln(sprintf(
            'Drupal.org overlap: %s',
            $this->humanizeOverlapLabel(is_string($comparison['verdict'] ?? null) ? $comparison['verdict'] : 'unknown'),
        ));
        $candidates = is_array($result['candidates'] ?? null) ? $result['candidates'] : [];
        $this->output->writeln(sprintf('Candidate projects evaluated: %d', count($candidates)));
        $source = is_array($result['source'] ?? null) ? $result['source'] : [];
        $redactions = is_array($source['redactions'] ?? null) ? $source['redactions'] : [];
        $this->output->writeln(sprintf('Sensitive values redacted locally: %d', count($redactions)));

        $this->writeAiMetadata('AI #1', $result['ai']['review'] ?? null);
        $this->writeAiMetadata('AI #2', $result['ai']['comparison'] ?? null);

        $tokenUsage = $this->sumTokenUsage($result['ai'] ?? null);
        if ($tokenUsage === null) {
            $this->output->writeln('Tokens used: unavailable');
        } else {
            $this->output->writeln(sprintf(
                'Tokens used: input %s, output %s, total %s',
                $this->formatTokenValue($tokenUsage['input']),
                $this->formatTokenValue($tokenUsage['output']),
                $this->formatTokenValue($tokenUsage['total']),
            ));
        }

        $this->writePotentialOverlaps(
            $comparison,
            is_array($result['candidates'] ?? null) ? $result['candidates'] : [],
            is_array($result['candidateCompatibility'] ?? null) ? $result['candidateCompatibility'] : [],
            is_array($result['core'] ?? null) ? $result['core'] : [],
        );
        $this->writePdfPath($pdfPath);
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
     * @param array<string, array<string, mixed>> $compatibilities
     * @param array{runtimeVersion: ?string, targetMajor: ?int} $core
     */
    private function writePotentialOverlaps(
        array $comparison,
        array $candidates,
        array $compatibilities,
        array $core,
    ): void {
        if (!array_key_exists('candidates', $comparison) || !is_array($comparison['candidates'])) {
            return;
        }
        $matches = $comparison['candidates'];
        if ($matches === []) {
            return;
        }

        $this->output->writeln('Potential overlaps:');
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
            $this->output->writeln($title . ' (' . $machineName . ')');
            $this->output->writeln('  Functional assessment: ' . $this->humanizeReviewLabel($assessment));
            $compatibility = is_array($compatibilities[$machineName] ?? null)
                ? $compatibilities[$machineName]
                : [];
            $status = is_string($compatibility['status'] ?? null) ? $compatibility['status'] : 'unknown';
            $targetMajor = is_int($compatibility['targetCoreMajor'] ?? null)
                ? $compatibility['targetCoreMajor']
                : (is_int($core['targetMajor'] ?? null) ? $core['targetMajor'] : null);
            $this->output->writeln('  Core compatibility: ' . $this->compatibilityLabel($status, $targetMajor));
            ++$shown;
            if ($shown === 5) {
                break;
            }
        }
    }

    private function humanizeReviewLabel(string $value): string
    {
        return match ($value) {
            'likely_duplicate' => 'Likely Duplicate',
            'possible_overlap' => 'Possible Overlap',
            default => 'Unknown',
        };
    }

    private function humanizeOverlapLabel(string $value): string
    {
        return match ($value) {
            'likely_duplicate' => 'Likely Duplicate',
            'possible_overlap' => 'Possible Overlap',
            'no_clear_duplicate' => 'No Clear Duplicate',
            default => 'Unknown',
        };
    }

    private function humanizeReusabilityLabel(string $value): string
    {
        return match ($value) {
            'reusable' => 'Reusable',
            'needs_generalization' => 'Needs Generalization',
            'client_specific' => 'Client Specific',
            'unclear' => 'Unclear',
            default => 'Unknown',
        };
    }

    private function humanizeReadinessLabel(string $value): string
    {
        return match ($value) {
            'ready' => 'Ready',
            'needs_changes' => 'Needs Changes',
            'significant_changes' => 'Significant Changes',
            'unclear' => 'Unclear',
            default => 'Unknown',
        };
    }

    private function compatibilityLabel(string $status, ?int $targetMajor): string
    {
        return match ($status) {
            'compatible' => 'Compatible with ' . ($targetMajor === null ? 'target Drupal' : 'Drupal ' . $targetMajor),
            'outdated' => 'Outdated for ' . ($targetMajor === null ? 'target Drupal' : 'Drupal ' . $targetMajor),
            'requires_newer_core' => 'Requires a newer Drupal core',
            default => 'Not verified',
        };
    }
}
