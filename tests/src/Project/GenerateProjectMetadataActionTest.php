<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Action\Project\GenerateProjectMetadataAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenerateProjectMetadataAction::class)]
final class GenerateProjectMetadataActionTest extends TestCase
{
    public function testGeneratesTypedMetadataUsingStructuredChat(): void
    {
        $calls = [];
        $action = new GenerateProjectMetadataAction(
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

        $result = $action(self::input());

        self::assertSame('local-provider', $result->providerId);
        self::assertSame(15, $result->tokenUsage['total']);
        self::assertSame('Manages reusable profile records.', $result->summary);
        self::assertCount(4, $calls);
        self::assertStringNotContainsString('source code', strtolower($calls[2]));
        self::assertStringNotContainsString('candidates', strtolower($calls[2]));
    }

    public function testPreservesUnavailableTokenUsage(): void
    {
        $action = new GenerateProjectMetadataAction(
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
            $action(self::input())->tokenUsage,
        );
    }

    public function testPropagatesProviderFailure(): void
    {
        $action = new GenerateProjectMetadataAction(
            static function (): array {
                throw new \RuntimeException('provider failed');
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider failed');
        $action(self::input());
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
