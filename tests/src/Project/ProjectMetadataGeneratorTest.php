<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ProjectMetadataGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectMetadataGenerator::class)]
class ProjectMetadataGeneratorTest extends TestCase
{
    public function testGeneratesAndValidatesMetadataUsingStructuredChat(): void
    {
        $calls = [];
        $generator = new ProjectMetadataGenerator(
            static function (...$arguments) use (&$calls): array {
                $calls = $arguments;

                return [
                    'providerId' => 'local-provider',
                    'modelId' => 'local-model',
                    'result' => [
                        'summary' => 'Manages reusable profile records.',
                        'description' => 'Provides profile record management for Drupal sites.',
                        'features' => ['Create records', 'List records'],
                        'integrations' => [],
                    ],
                    'tokenUsage' => ['input' => 10, 'output' => 5, 'total' => 15],
                ];
            },
        );

        $result = $generator->generate(self::input());

        self::assertSame('local-provider', $result['providerId']);
        self::assertSame(15, $result['tokenUsage']['total']);
        self::assertSame('Manages reusable profile records.', $result['metadata']['summary']);
        self::assertCount(4, $calls);
        self::assertStringNotContainsString('source code', strtolower($calls[2]));
        self::assertStringNotContainsString('candidates', strtolower($calls[2]));
    }

    public function testPreservesUnavailableTokenUsage(): void
    {
        $generator = new ProjectMetadataGenerator(
            static fn (...$arguments): array => [
                'providerId' => 'provider',
                'modelId' => 'model',
                'result' => [
                    'summary' => 'Summary.',
                    'description' => 'Description.',
                    'features' => [],
                    'integrations' => [],
                ],
                'tokenUsage' => ['input' => null, 'output' => null, 'total' => null],
            ],
        );

        self::assertSame(
            ['input' => null, 'output' => null, 'total' => null],
            $generator->generate(self::input())['tokenUsage'],
        );
    }

    public function testPropagatesProviderFailure(): void
    {
        $generator = new ProjectMetadataGenerator(
            static function (): array {
                throw new \RuntimeException('provider failed');
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider failed');
        $generator->generate(self::input());
    }

    /**
     * @return array<string, mixed>
     */
    private static function input(): array
    {
        return [
            'moduleName' => 'example_module',
            'moduleTitle' => 'Example Module',
            'type' => 'module',
            'coreVersionRequirement' => '^11',
            'dependencies' => [],
            'review' => [
                'purpose' => 'Provides reusable profile records.',
                'capabilities' => ['Create profile records'],
                'drupal_concepts' => ['content entities'],
                'integrations' => [],
                'reusability' => ['status' => 'reusable'],
            ],
            'sourceCoverage' => 'full',
            'runtime' => [
                'type' => 'local-drush',
                'workingDirectory' => '/tmp',
                'command' => ['drush'],
            ],
        ];
    }
}
