<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\DrupalAiBridge;
use mglaman\DrupalOrg\Project\ProjectMetadataPromptBuilder;
use mglaman\DrupalOrg\Project\ProjectMetadataResultValidator;
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;

final class GenerateProjectMetadataAction implements ActionInterface
{
    /**
     * @param \Closure|null $structuredChat
     */
    public function __construct(
        private readonly ?\Closure $structuredChat = null,
    ) {
    }

    /**
     * @param array{
     *     type: 'ddev'|'lando'|'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * } $runtime
     * @param list<string> $dependencies
     * @param array{
     *     purpose: string,
     *     capabilities: list<string>,
     *     drupal_concepts: list<string>,
     *     integrations: list<string>,
     *     reusability: array{status: string}
     * } $review
     * @param 'full'|'partial' $sourceCoverage
     * @param callable|null $onWait
     */
    public function __invoke(
        array $runtime,
        string $machineName,
        string $title,
        string $projectType,
        string $coreVersionRequirement,
        array $dependencies,
        array $review,
        string $sourceCoverage,
        ?callable $onWait = null,
    ): ProjectMetadataResult {
        $prompts = (new ProjectMetadataPromptBuilder())->build([
            'moduleName' => $machineName,
            'moduleTitle' => $title,
            'type' => $projectType,
            'coreVersionRequirement' => $coreVersionRequirement,
            'dependencies' => $dependencies,
            'review' => $review,
            'sourceCoverage' => $sourceCoverage,
        ]);
        $response = $this->callStructuredChat($runtime, $prompts, $onWait);
        $metadata = (new ProjectMetadataResultValidator())->validate($response['result']);

        return new ProjectMetadataResult(
            title: $title,
            machineName: $machineName,
            projectType: $projectType,
            coreVersionRequirement: $coreVersionRequirement,
            dependencies: $dependencies,
            summary: $metadata['summary'],
            description: $metadata['description'],
            features: $metadata['features'],
            integrations: $metadata['integrations'],
            providerId: $response['providerId'],
            modelId: $response['modelId'],
            tokenUsage: $response['tokenUsage'],
            categories: $metadata['categories'],
            ecosystems: $metadata['ecosystem'],
            supportingOrganizations: array_map(
                static fn(array $organization): array => [
                    'name' => $organization['name'],
                    'howTheyHelped' => $organization['how_they_helped'],
                ],
                $metadata['supporting_organizations'],
            ),
            issues: [
                'components' => $metadata['issues']['components'],
                'submissionGuidelines' => $metadata['issues']['submission_guidelines'],
                'summaryTemplate' => $metadata['issues']['summary_template'],
                'enableTracker' => $metadata['issues']['enable_tracker'],
            ],
            resources: [
                'homepage' => $metadata['resources']['homepage'],
                'changelog' => $metadata['resources']['changelog'],
                'demo' => $metadata['resources']['demo'],
                'documentation' => $metadata['resources']['documentation'],
                'screenshots' => $metadata['resources']['screenshots'],
            ],
        );
    }

    /**
     * @param array{
     *     type: 'ddev'|'lando'|'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * } $runtime
     * @param array{systemPrompt: string, userPrompt: string, schema: array<string, mixed>} $prompts
     *
     * @return array{
     *     providerId: string,
     *     modelId: string,
     *     result: array<string, mixed>,
     *     tokenUsage: array{input: ?int, output: ?int, total: ?int}
     * }
     */
    private function callStructuredChat(array $runtime, array $prompts, ?callable $onWait = null): array
    {
        if ($this->structuredChat !== null) {
            $response = $onWait === null
                ? ($this->structuredChat)(
                    $runtime,
                    $prompts['systemPrompt'],
                    $prompts['userPrompt'],
                    $prompts['schema'],
                )
                : ($this->structuredChat)(
                    $runtime,
                    $prompts['systemPrompt'],
                    $prompts['userPrompt'],
                    $prompts['schema'],
                    $onWait,
                );
        } else {
            $response = (new DrupalAiBridge())->structuredChat(
                $runtime,
                $prompts['systemPrompt'],
                $prompts['userPrompt'],
                $prompts['schema'],
                $onWait,
            );
        }

        if (!is_array($response)
            || !is_string($response['providerId'] ?? null)
            || !is_string($response['modelId'] ?? null)
            || !is_array($response['result'] ?? null)
            || !is_array($response['tokenUsage'] ?? null)
        ) {
            throw new \RuntimeException('Drupal AI returned an invalid project metadata response.');
        }

        $tokenUsage = $response['tokenUsage'];
        foreach (['input', 'output', 'total'] as $key) {
            if (!array_key_exists($key, $tokenUsage)
                || (!is_int($tokenUsage[$key]) && $tokenUsage[$key] !== null)
            ) {
                throw new \RuntimeException('Drupal AI returned invalid project metadata token usage.');
            }
        }

        /** @var array{
         *     providerId: string,
         *     modelId: string,
         *     result: array<string, mixed>,
         *     tokenUsage: array{input: ?int, output: ?int, total: ?int}
         * } $response */
        return $response;
    }
}
