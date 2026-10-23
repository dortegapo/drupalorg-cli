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

        $result = $action(...self::input());

        self::assertSame('Example Module', $result->title);
        self::assertSame('example_module', $result->machineName);
        self::assertSame('module', $result->projectType);
        self::assertSame('^11', $result->coreVersionRequirement);
        self::assertSame([], $result->dependencies);
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
            $action(...self::input())->tokenUsage,
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
        $action(...self::input());
    }

    /**
     * @return array{
     *     runtime: array{type: 'local-drush', workingDirectory: string, command: list<string>},
     *     machineName: string,
     *     title: string,
     *     projectType: string,
     *     coreVersionRequirement: string,
     *     dependencies: list<string>,
     *     review: array{
     *         purpose: string,
     *         capabilities: list<string>,
     *         drupal_concepts: list<string>,
     *         integrations: list<string>,
     *         reusability: array{status: string}
     *     },
     *     sourceCoverage: 'full'|'partial'
     * }
     */
    private static function input(): array
    {
        return [
            'runtime' => [
                'type' => 'local-drush',
                'workingDirectory' => '/tmp',
                'command' => ['drush'],
            ],
            'machineName' => 'example_module',
            'title' => 'Example Module',
            'projectType' => 'module',
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
        ];
    }
}
