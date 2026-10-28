<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrgCli\Command\Project\ProjectMetadataRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

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
        self::assertStringContainsString('DRUPAL.ORG METADATA', $display);
        self::assertStringContainsString("Summary\nManages reusable records.", $display);
        self::assertStringContainsString("Description\nProvides reusable record management.", $display);
        self::assertStringContainsString("Features\n- Create records", $display);
        self::assertStringContainsString("Integrations\n- Views", $display);
        self::assertStringNotContainsString('Title', $display);
        self::assertStringNotContainsString('Declared dependencies', $display);
        self::assertStringNotContainsString('Tokens:', $display);
    }

    public function testVerboseOutputIncludesMetadataDetails(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
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
        self::assertStringContainsString('Metadata details', $display);
        self::assertStringContainsString('Title: Example Module', $display);
        self::assertStringContainsString('Machine name: example_module', $display);
        self::assertStringContainsString('Declared dependencies:', $display);
        self::assertStringContainsString('  - drupal:views', $display);
        self::assertStringContainsString('AI: provider / model', $display);
        self::assertStringContainsString('Tokens: input 1 · output 2 · total 3', $display);
    }
}
