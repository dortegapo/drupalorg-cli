<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Output\OutputInterface;

final class ModuleReviewProgressRenderer
{
    private ?ProgressIndicator $indicator = null;

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
            'source_prepared' => $this->output->writeln('Module source prepared.'),
            'review_ai_started' => $this->startIndicator('Analyzing module with AI...'),
            'review_ai_tick' => $this->advanceIndicator(),
            'review_ai_completed' => $this->finishIndicator('Module analysis completed.'),
            'review_ai_failed' => $this->finishIndicator('Module analysis failed.'),
            'project_search_started' => $this->output->writeln('Searching Drupal.org projects...'),
            'project_search_progress' => $this->writeSearchProgress($context),
            'project_search_completed' => $this->output->writeln('Drupal.org project searches completed.'),
            'candidate_ranking_completed' => $this->writeCandidateCount($context),
            'comparison_ai_started' => $this->startIndicator(sprintf(
                'Comparing %d Drupal.org candidates with AI...',
                $this->integerContext($context, 'candidateCount'),
            )),
            'comparison_ai_tick' => $this->advanceIndicator(),
            'comparison_ai_completed' => $this->finishIndicator('Candidate comparison completed.'),
            'comparison_ai_failed' => $this->finishIndicator('Candidate comparison failed.'),
            'assessment_completed' => $this->output->writeln('Contribution assessment completed.'),
            'core_version_started' => $this->output->writeln('Checking Drupal core version...'),
            'core_version_completed' => $this->output->writeln('Drupal core version check completed.'),
            'compatibility_started' => $this->output->writeln('Checking project compatibility...'),
            'compatibility_progress' => $this->writeCompatibilityProgress($context),
            'compatibility_completed' => $this->output->writeln('Core compatibility checks completed.'),
            'analysis_completed' => $this->output->writeln('Module review analysis completed.'),
            default => null,
        };
    }

    public function finishOnFailure(): void
    {
        if ($this->indicator !== null) {
            $this->finishIndicator('Module analysis failed.');
        }
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
        $current = $this->integerContext($context, 'current');
        $total = $this->integerContext($context, 'total');
        $term = is_string($context['term'] ?? null) ? $context['term'] : 'unknown';
        $type = is_string($context['type'] ?? null) ? $context['type'] : 'unknown';
        $this->output->writeln(sprintf(
            'Searching Drupal.org projects... %d/%d (%s: %s)',
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
        $this->output->writeln(sprintf(
            'Candidate projects found: %d',
            $this->integerContext($context, 'candidateCount'),
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function writeCompatibilityProgress(array $context): void
    {
        $current = $this->integerContext($context, 'current');
        $total = $this->integerContext($context, 'total');
        $machineName = is_string($context['machineName'] ?? null) ? $context['machineName'] : 'unknown';
        $this->output->writeln(sprintf(
            'Checking project compatibility... %d/%d (%s)',
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
}
