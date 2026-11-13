<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use mglaman\DrupalOrgCli\Command\Project\ModuleReviewSummaryRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(ModuleReviewSummaryRenderer::class)]
final class ModuleReviewSummaryRendererTest extends TestCase
{
    public function testRendersStructuredReviewSummaryWithoutPerformingWork(): void
    {
        $output = new BufferedOutput();
        $renderer = new ModuleReviewSummaryRenderer($output);

        $renderer->render([
            'complete' => true,
            'assessment' => ['status' => 'candidate_with_changes'],
            'review' => [
                'reusability' => ['status' => 'reusable'],
                'contrib_readiness' => ['status' => 'ready'],
            ],
            'comparison' => [
                'verdict' => 'possible_overlap',
                'candidates' => [],
            ],
            'candidates' => [],
            'source' => ['redactions' => []],
            'ai' => [
                'review' => [
                    'providerId' => 'provider',
                    'modelId' => 'model',
                    'tokenUsage' => ['input' => 1, 'output' => 2, 'total' => 3],
                ],
                'comparison' => null,
            ],
        ], '/tmp/review.pdf');

        $display = $output->fetch();
        self::assertStringContainsString('Assessment           CANDIDATE WITH CHANGES', $display);
        self::assertStringContainsString('Reusability          REUSABLE', $display);
        self::assertStringContainsString('Drupal.org overlap   POSSIBLE OVERLAP', $display);
        self::assertStringNotContainsString('AI #1:', $display);
        self::assertStringNotContainsString('Tokens:', $display);
        self::assertStringContainsString('Full report: /tmp/review.pdf', $display);
    }

    public function testVerboseOutputIncludesReviewDiagnostics(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $renderer = new ModuleReviewSummaryRenderer($output);

        $renderer->render([
            'complete' => true,
            'assessment' => ['status' => 'candidate'],
            'review' => [
                'reusability' => ['status' => 'needs_generalization'],
                'contrib_readiness' => ['status' => 'needs_changes'],
            ],
            'comparison' => ['verdict' => 'no_clear_duplicate', 'candidates' => []],
            'candidates' => array_fill(0, 4, ['machineName' => 'candidate']),
            'source' => [
                'coverage' => ['mode' => 'selected'],
                'redactions' => [['type' => 'token']],
            ],
            'ai' => [
                'review' => [
                    'providerId' => 'provider',
                    'modelId' => 'model',
                    'tokenUsage' => ['input' => 1, 'output' => 2, 'total' => 3],
                ],
                'comparison' => null,
            ],
        ]);

        $display = $output->fetch();
        self::assertStringContainsString('Review details', $display);
        self::assertStringContainsString('Source coverage: partial', $display);
        self::assertStringContainsString('Candidates evaluated: 4', $display);
        self::assertStringContainsString('Local redactions: 1', $display);
        self::assertStringContainsString('AI #1: provider / model', $display);
        self::assertStringContainsString('Tokens: input 1 · output 2 · total 3', $display);
    }
}
