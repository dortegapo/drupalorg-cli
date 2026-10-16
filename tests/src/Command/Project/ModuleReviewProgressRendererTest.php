<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Tests\Command\Project;

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
}
