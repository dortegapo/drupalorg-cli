<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use mglaman\DrupalOrgCli\Command\Project\ModuleReviewProgressRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\StreamOutput;

#[CoversClass(ModuleReviewProgressRenderer::class)]
final class ModuleReviewProgressRendererTest extends TestCase
{
    public function testNonDecoratedOutputDoesNotPrintEveryAiTick(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $output = new StreamOutput($stream);
        $output->setDecorated(false);
        $renderer = new ModuleReviewProgressRenderer($output);

        $renderer->handle('review_ai_started');
        for ($index = 0; $index < 10; ++$index) {
            $renderer->handle('review_ai_tick');
        }
        $renderer->handle('review_ai_completed');

        rewind($stream);
        $display = stream_get_contents($stream);
        self::assertIsString($display);
        self::assertSame(1, substr_count($display, 'Analyzing module with AI...'));
        self::assertSame(1, substr_count($display, 'Module analysis completed.'));
    }

    public function testRendersCoreAndCompatibilityProgress(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $output = new StreamOutput($stream);
        $output->setDecorated(false);
        $renderer = new ModuleReviewProgressRenderer($output);

        $renderer->handle('core_version_started');
        $renderer->handle('core_version_completed');
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
        self::assertStringContainsString('Checking Drupal core version.', $display);
        self::assertStringContainsString('Drupal core version check completed.', $display);
        self::assertStringContainsString('Checking project compatibility... 1/2 (coffee)', $display);
        self::assertStringContainsString('Checking project compatibility... 2/2 (eazylaunch)', $display);
        self::assertStringContainsString('Core compatibility checks completed.', $display);
    }
}
