<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Project\ModuleReviewResult;

final class ModuleReviewProgressRenderer
{
    public function __construct(
        private readonly ProjectPublishProgressRenderer $progress,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function handle(string $event, array $context = []): void
    {
        match ($event) {
            'source_prepared' => $this->progress->detail('Module source prepared.'),
            'review_ai_started' => $this->progress->start(ProjectPublishProgressRenderer::ANALYZING_MODULE),
            'review_ai_tick' => $this->progress->tick(),
            'review_ai_completed' => $this->progress->complete(ProjectPublishProgressRenderer::ANALYZING_MODULE),
            'review_ai_failed' => $this->progress->fail(ProjectPublishProgressRenderer::ANALYZING_MODULE),
            'project_search_started' => $this->startSearch($context),
            'project_search_progress' => $this->searchProgress($context),
            'project_search_completed' => $this->progress->complete(ProjectPublishProgressRenderer::SEARCHING_DRUPAL_ORG),
            'project_search_failed' => $this->progress->fail(ProjectPublishProgressRenderer::SEARCHING_DRUPAL_ORG),
            'candidate_ranking_completed' => $this->progress->detail(sprintf(
                'Candidates evaluated: %d',
                $this->integerContext($context, 'candidateCount'),
            )),
            'comparison_ai_started' => $this->progress->start(ProjectPublishProgressRenderer::COMPARING_PROJECTS),
            'comparison_ai_tick' => $this->progress->tick(),
            'comparison_ai_completed' => $this->progress->complete(ProjectPublishProgressRenderer::COMPARING_PROJECTS),
            'comparison_ai_failed' => $this->progress->fail(ProjectPublishProgressRenderer::COMPARING_PROJECTS),
            'assessment_completed' => $this->progress->detail('Contribution assessment completed.'),
            'compatibility_started' => $this->progress->start(ProjectPublishProgressRenderer::CHECKING_COMPATIBILITY),
            'compatibility_progress' => $this->compatibilityProgress($context),
            'compatibility_completed' => $this->progress->complete(ProjectPublishProgressRenderer::CHECKING_COMPATIBILITY),
            'analysis_completed' => $this->progress->detail('Module review analysis completed.'),
            default => null,
        };
    }

    public function finishOnFailure(?ModuleReviewResult $result = null): void
    {
        $stage = $result === null || $result->limitations === []
            ? null
            : $result->limitations[0]['stage'];
        $phase = match ($stage) {
            'collection', 'sanitization', 'source_empty' => ProjectPublishProgressRenderer::PREPARING_SOURCE,
            'review_prompt', 'review_ai', 'review_validation' => ProjectPublishProgressRenderer::ANALYZING_MODULE,
            'project_search' => ProjectPublishProgressRenderer::SEARCHING_DRUPAL_ORG,
            'candidate_ranking', 'comparison_prompt', 'comparison_ai', 'comparison_validation', 'assessment' => ProjectPublishProgressRenderer::COMPARING_PROJECTS,
            'compatibility' => ProjectPublishProgressRenderer::CHECKING_COMPATIBILITY,
            default => null,
        };
        if ($phase !== null && ($result?->complete ?? true) !== true) {
            $this->progress->fail($phase);
            return;
        }

        $this->progress->failActive();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function startSearch(array $context): void
    {
        $this->progress->start(ProjectPublishProgressRenderer::SEARCHING_DRUPAL_ORG);
        $this->progress->detail(sprintf(
            'Drupal.org search started (%d queries).',
            $this->integerContext($context, 'total'),
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function searchProgress(array $context): void
    {
        $this->progress->tick();
        $this->progress->detail(sprintf(
            'Search %d/%d · %s: %s',
            $this->integerContext($context, 'current'),
            $this->integerContext($context, 'total'),
            $this->stringContext($context, 'type'),
            $this->stringContext($context, 'term'),
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function compatibilityProgress(array $context): void
    {
        $this->progress->tick();
        $this->progress->detail(sprintf(
            'Compatibility %d/%d · %s',
            $this->integerContext($context, 'current'),
            $this->integerContext($context, 'total'),
            $this->stringContext($context, 'machineName'),
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function integerContext(array $context, string $key): int
    {
        return is_int($context[$key] ?? null) ? $context[$key] : 0;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function stringContext(array $context, string $key): string
    {
        return is_string($context[$key] ?? null) ? $context[$key] : 'unknown';
    }
}
