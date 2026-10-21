<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrgCli\Command\Project\ProjectMetadataRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(ProjectMetadataRenderer::class)]
final class ProjectMetadataRendererTest extends TestCase
{
    public function testRendersCompleteProjectMetadata(): void
    {
        $output = new BufferedOutput();
        $metadata = new ProjectMetadataResult(
            title: 'Example Module',
            machineName: 'example_module',
            projectType: 'module',
            coreVersionRequirement: '^11',
            dependencies: ['drupal:views'],
            summary: 'Manages reusable records.',
            description: 'Provides reusable record management.',
            features: ['Create records'],
            integrations: ['Views'],
            providerId: 'provider',
            modelId: 'model',
            tokenUsage: ['input' => 1, 'output' => 2, 'total' => 3],
        );

        (new ProjectMetadataRenderer())->render($output, $metadata);

        $display = $output->fetch();
        self::assertStringContainsString("Title\nExample Module", $display);
        self::assertStringContainsString("Machine name\nexample_module", $display);
        self::assertStringContainsString("Project type\nmodule", $display);
        self::assertStringContainsString("Summary\nManages reusable records.", $display);
        self::assertStringContainsString("Description\nProvides reusable record management.", $display);
        self::assertStringContainsString("Features\n- Create records", $display);
        self::assertStringContainsString("Integrations\n- Views", $display);
        self::assertStringContainsString("Core requirement\n^11", $display);
        self::assertStringContainsString("Declared dependencies\n- drupal:views", $display);
        self::assertStringContainsString('Tokens used: input 1, output 2, total 3', $display);
    }
}
