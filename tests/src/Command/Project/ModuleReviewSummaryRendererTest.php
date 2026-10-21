<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use mglaman\DrupalOrgCli\Command\Project\ModuleReviewSummaryRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

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
        self::assertStringContainsString('Assessment: Candidate with changes', $display);
        self::assertStringContainsString('Reusability: Reusable', $display);
        self::assertStringContainsString('Full report: /tmp/review.pdf', $display);
    }
}
