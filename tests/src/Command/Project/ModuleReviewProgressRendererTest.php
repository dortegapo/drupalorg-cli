<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use mglaman\DrupalOrg\Project\ModuleReviewResult;
use mglaman\DrupalOrgCli\Command\Project\ModuleReviewProgressRenderer;
use mglaman\DrupalOrgCli\Command\Project\ProjectPublishProgressRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

#[CoversClass(ModuleReviewProgressRenderer::class)]
final class ModuleReviewProgressRendererTest extends TestCase
{
    public function testNormalOutputPrintsOneLinePerAiPhase(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $output = new StreamOutput($stream);
        $output->setDecorated(false);
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register([
            ProjectPublishProgressRenderer::ANALYZING_MODULE => 'Analyzing module',
        ]);
        $renderer = new ModuleReviewProgressRenderer($progress);

        $renderer->handle('review_ai_started');
        for ($index = 0; $index < 10; ++$index) {
            $renderer->handle('review_ai_tick');
        }
        $renderer->handle('review_ai_completed');

        rewind($stream);
        $display = stream_get_contents($stream);
        self::assertIsString($display);
        self::assertStringContainsString('■ Analyzing module...', $display);
        self::assertStringContainsString('✓ Analyzing module', $display);
        self::assertStringNotContainsString('AI tick', $display);
    }

    public function testNormalOutputHidesDetailedCompatibilityProgress(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $output = new StreamOutput($stream);
        $output->setDecorated(false);
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register([
            ProjectPublishProgressRenderer::SEARCHING_DRUPAL_ORG => 'Searching Drupal.org',
            ProjectPublishProgressRenderer::CHECKING_COMPATIBILITY => 'Checking compatibility',
        ]);
        $renderer = new ModuleReviewProgressRenderer($progress);

        $renderer->handle('project_search_started', ['total' => 1]);
        $renderer->handle('project_search_progress', [
            'current' => 1,
            'total' => 1,
            'type' => 'primary',
            'term' => 'token',
        ]);
        $renderer->handle('project_search_completed');
        $renderer->handle('compatibility_started', ['current' => 0, 'total' => 2]);
        $renderer->handle('compatibility_progress', [
            'current' => 1,
            'total' => 2,
            'machineName' => 'coffee',
        ]);
        $renderer->handle('compatibility_progress', [
            'current' => 2,
            'total' => 2,
            'machineName' => 'eazylaunch',
        ]);
        $renderer->handle('compatibility_completed', ['current' => 2, 'total' => 2]);

        rewind($stream);
        $display = stream_get_contents($stream);
        self::assertIsString($display);
        self::assertStringContainsString('✓ Checking compatibility', $display);
        self::assertStringNotContainsString('coffee', $display);
        self::assertStringNotContainsString('eazylaunch', $display);
        self::assertStringNotContainsString('token', $display);
    }

    public function testVerboseOutputIncludesSearchAndCompatibilityDetails(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $output = new StreamOutput($stream);
        $output->setDecorated(false);
        $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register([
            ProjectPublishProgressRenderer::SEARCHING_DRUPAL_ORG => 'Searching Drupal.org',
            ProjectPublishProgressRenderer::CHECKING_COMPATIBILITY => 'Checking compatibility',
        ]);
        $renderer = new ModuleReviewProgressRenderer($progress);

        $renderer->handle('project_search_started', ['total' => 2]);
        $renderer->handle('project_search_progress', [
            'current' => 1,
            'total' => 2,
            'type' => 'core',
            'term' => 'token',
        ]);
        $renderer->handle('project_search_completed');
        $renderer->handle('compatibility_started', ['total' => 1]);
        $renderer->handle('compatibility_progress', [
            'current' => 1,
            'total' => 1,
            'machineName' => 'token',
        ]);
        $renderer->handle('compatibility_completed');

        rewind($stream);
        $display = stream_get_contents($stream);
        self::assertIsString($display);
        self::assertStringContainsString('Search 1/2 · core: token', $display);
        self::assertStringContainsString('Compatibility 1/1 · token', $display);
        self::assertStringNotContainsString("\r", $display);
    }

    public function testIncompleteAnalysisMarksTheFailedParentPhase(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $output = new StreamOutput($stream);
        $output->setDecorated(false);
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register([
            ProjectPublishProgressRenderer::ANALYZING_MODULE => 'Analyzing module',
        ]);
        $renderer = new ModuleReviewProgressRenderer($progress);

        $renderer->finishOnFailure(ModuleReviewResult::fromArray([
            'complete' => false,
            'limitations' => [
                ['stage' => 'review_validation'],
            ],
        ]));

        rewind($stream);
        $display = stream_get_contents($stream);
        self::assertIsString($display);
        self::assertStringContainsString('✗ Analyzing module', $display);
    }
}
