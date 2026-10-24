<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Output\OutputInterface;

final class ModuleReviewProgressRenderer
{
    private ?ProgressIndicator $indicator = null;

    private ?string $activePhase = null;

    public function __construct(
        private readonly OutputInterface $output,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function handle(string $event, array $context = []): void
    {
        match ($event) {
            'source_prepared' => $this->writeVerbose('Module source prepared.'),
            'review_ai_started' => $this->startPhase('Analyzing module', 'Analyzing module with AI...'),
            'review_ai_tick' => $this->advanceIndicator(),
            'review_ai_completed' => $this->finishPhase('Analyzing module', 'Module analysis completed.'),
            'review_ai_failed' => $this->failPhase('Analyzing module', 'Module analysis failed.'),
            'project_search_started' => $this->startPhase(
                'Searching Drupal.org',
                sprintf(
                    'Drupal.org search started (%d queries).',
                    $this->integerContext($context, 'total'),
                ),
                false,
            ),
            'project_search_progress' => $this->writeSearchProgress($context),
            'project_search_completed' => $this->finishPhase(
                'Searching Drupal.org',
                'Drupal.org search completed.',
            ),
            'project_search_failed' => $this->failPhase(
                'Searching Drupal.org',
                'Drupal.org search failed.',
            ),
            'candidate_ranking_completed' => $this->writeCandidateCount($context),
            'comparison_ai_started' => $this->startPhase(
                'Comparing candidates',
                sprintf(
                    'Comparing %d Drupal.org candidates with AI...',
                    $this->integerContext($context, 'candidateCount'),
                ),
            ),
            'comparison_ai_tick' => $this->advanceIndicator(),
            'comparison_ai_completed' => $this->finishPhase(
                'Comparing candidates',
                'Candidate comparison completed.',
            ),
            'comparison_ai_failed' => $this->failPhase(
                'Comparing candidates',
                'Candidate comparison failed.',
            ),
            'assessment_completed' => $this->writeVerbose('Contribution assessment completed.'),
            'core_version_started' => $this->writeVerbose('Checking Drupal core version...'),
            'core_version_completed' => $this->writeVerbose('Drupal core version check completed.'),
            'compatibility_started' => $this->startPhase(
                'Checking compatibility',
                sprintf(
                    'Compatibility checks started (%d candidates).',
                    $this->integerContext($context, 'total'),
                ),
                false,
            ),
            'compatibility_progress' => $this->writeCompatibilityProgress($context),
            'compatibility_completed' => $this->finishPhase(
                'Checking compatibility',
                'Core compatibility checks completed.',
            ),
            'analysis_completed' => $this->writeVerbose('Module review analysis completed.'),
            default => null,
        };
    }

    public function finishOnFailure(): void
    {
        if ($this->indicator !== null) {
            $this->finishIndicator('Module analysis failed.');
            $this->activePhase = null;
        } elseif ($this->activePhase !== null) {
            $this->output->writeln($this->activePhase . '... failed');
            $this->activePhase = null;
        }
    }

    private function startPhase(string $normalLabel, string $verboseMessage, bool $useIndicator = true): void
    {
        $this->activePhase = $normalLabel;
        if ($this->output->isVerbose() && $useIndicator) {
            $this->startIndicator($verboseMessage);
        } elseif ($this->output->isVerbose()) {
            $this->output->writeln('<comment>' . $verboseMessage . '</comment>');
        }
    }

    private function finishPhase(string $normalLabel, string $verboseMessage): void
    {
        if ($this->indicator !== null) {
            $this->finishIndicator($verboseMessage);
        } elseif (!$this->output->isVerbose()) {
            $this->output->writeln($normalLabel . '... done');
        } else {
            $this->output->writeln('<comment>' . $verboseMessage . '</comment>');
        }
        $this->activePhase = null;
    }

    private function failPhase(string $normalLabel, string $verboseMessage): void
    {
        if ($this->indicator !== null) {
            $this->finishIndicator($verboseMessage);
        } else {
            $this->output->writeln($normalLabel . '... failed');
        }
        $this->activePhase = null;
    }

    private function startIndicator(string $message): void
    {
        if ($this->indicator !== null) {
            $this->finishIndicator('Previous analysis phase completed.');
        }

        $this->indicator = new ProgressIndicator($this->output);
        $this->indicator->start($message);
    }

    private function advanceIndicator(): void
    {
        $this->indicator?->advance();
    }

    private function finishIndicator(string $message): void
    {
        if ($this->indicator === null) {
            return;
        }

        $this->indicator->finish($message);
        $this->indicator = null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function writeSearchProgress(array $context): void
    {
        if (!$this->output->isVerbose()) {
            return;
        }

        $current = $this->integerContext($context, 'current');
        $total = $this->integerContext($context, 'total');
        $term = is_string($context['term'] ?? null) ? $context['term'] : 'unknown';
        $type = is_string($context['type'] ?? null) ? $context['type'] : 'unknown';
        $this->output->writeln(sprintf(
            '<comment>Search %d/%d · %s: %s</comment>',
            $current,
            $total,
            $type,
            $term,
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function writeCandidateCount(array $context): void
    {
        if (!$this->output->isVerbose()) {
            return;
        }

        $this->output->writeln(sprintf(
            '<comment>Candidates evaluated: %d</comment>',
            $this->integerContext($context, 'candidateCount'),
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function writeCompatibilityProgress(array $context): void
    {
        if (!$this->output->isVerbose()) {
            return;
        }

        $current = $this->integerContext($context, 'current');
        $total = $this->integerContext($context, 'total');
        $machineName = is_string($context['machineName'] ?? null) ? $context['machineName'] : 'unknown';
        $this->output->writeln(sprintf(
            '<comment>Compatibility %d/%d · %s</comment>',
            $current,
            $total,
            $machineName,
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function integerContext(array $context, string $key): int
    {
        return is_int($context[$key] ?? null) ? $context[$key] : 0;
    }

    private function writeVerbose(string $message): void
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln('<comment>' . $message . '</comment>');
        }
    }
}
