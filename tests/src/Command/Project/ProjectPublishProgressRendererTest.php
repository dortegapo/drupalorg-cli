<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use mglaman\DrupalOrgCli\Command\Project\ProjectPublishProgressRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(ProjectPublishProgressRenderer::class)]
final class ProjectPublishProgressRendererTest extends TestCase
{
    public function testFallbackRendersPhaseTransitionsDeterministically(): void
    {
        $output = new BufferedOutput();
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register([
            'first' => 'First phase',
            'second' => 'Second phase',
        ]);

        $progress->start('first');
        $progress->tick();
        $progress->complete('first');
        $progress->start('second');
        $progress->complete('second');

        $display = $output->fetch();
        self::assertSame(
            "■ First phase...\n✓ First phase\n■ Second phase...\n✓ Second phase\n",
            $display,
        );
    }

    public function testOnlyOnePhaseCanBeActive(): void
    {
        $progress = new ProjectPublishProgressRenderer(new BufferedOutput());
        $progress->register([
            'first' => 'First phase',
            'second' => 'Second phase',
        ]);
        $progress->start('first');

        $this->expectException(\LogicException::class);
        $progress->start('second');
    }

    public function testTicksDoNotCreateAdditionalOutputLines(): void
    {
        $output = new BufferedOutput();
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register(['first' => 'First phase']);
        $progress->start('first');
        $progress->tick();
        $progress->tick();

        self::assertSame("■ First phase...\n", $output->fetch());
    }

    public function testSuspendingProgressStopsTicksUntilResumed(): void
    {
        $output = new BufferedOutput();
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register(['first' => 'First phase']);
        $progress->start('first');
        $progress->suspend();
        $progress->tick();
        $progress->resume();
        $progress->complete('first');

        self::assertSame("■ First phase...\n✓ First phase\n", $output->fetch());
    }

    public function testFinishMarksTheActivePhaseAsFailedAndReleasesIt(): void
    {
        $output = new BufferedOutput();
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register([
            'first' => 'First phase',
            'second' => 'Second phase',
        ]);
        $progress->start('first');
        $progress->finish();
        $progress->start('second');

        self::assertSame(
            "■ First phase...\n✗ First phase\n■ Second phase...\n",
            $output->fetch(),
        );
    }

    public function testTerminalPhaseTransitionsAreIdempotent(): void
    {
        $output = new BufferedOutput();
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register(['first' => 'First phase']);
        $progress->start('first');
        $progress->complete('first');
        $progress->complete('first');
        $progress->fail('first');
        $progress->start('first');

        self::assertSame("■ First phase...\n✓ First phase\n", $output->fetch());
    }

    public function testReviewPhasesAreOptionalAndRegisteredTogether(): void
    {
        $output = new BufferedOutput();
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register([
            ProjectPublishProgressRenderer::PREPARING_MODULE => 'Preparing module',
        ]);
        $progress->start(ProjectPublishProgressRenderer::PREPARING_MODULE);
        $progress->complete(ProjectPublishProgressRenderer::PREPARING_MODULE);

        self::assertStringNotContainsString('Analyzing module', $output->fetch());

        $progress->registerReviewPhases();
        $progress->start(ProjectPublishProgressRenderer::ANALYZING_MODULE);

        self::assertStringContainsString('■ Analyzing module...', $output->fetch());
    }
}
