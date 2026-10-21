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
        private readonly ?ProjectMetadataPromptBuilder $promptBuilder = null,
        private readonly ?ProjectMetadataResultValidator $resultValidator = null,
        private readonly ?DrupalAiBridge $aiBridge = null,
    ) {
    }

    /**
     * @param array{
     *     moduleName: string,
     *     moduleTitle: string,
     *     type: string,
     *     coreVersionRequirement: string,
     *     dependencies: list<string>,
     *     review: array{
     *         purpose: string,
     *         capabilities: list<string>,
     *         drupal_concepts: list<string>,
     *         integrations: list<string>,
     *         reusability: array{status: string}
     *     },
     *     sourceCoverage: 'full'|'partial',
     *     runtime: array<string, mixed>
     * } $input
     */
    public function __invoke(array $input): ProjectMetadataResult
    {
        $builder = $this->promptBuilder ?? new ProjectMetadataPromptBuilder();
        $prompts = $builder->build($input);
        $response = $this->callStructuredChat($input['runtime'], $prompts);
        $validator = $this->resultValidator ?? new ProjectMetadataResultValidator();
        $metadata = $validator->validate($response['result']);

        return new ProjectMetadataResult(
            summary: $metadata['summary'],
            description: $metadata['description'],
            features: $metadata['features'],
            integrations: $metadata['integrations'],
            providerId: $response['providerId'],
            modelId: $response['modelId'],
            tokenUsage: $response['tokenUsage'],
        );
    }

    /**
     * @param array<string, mixed> $runtime
     * @param array{systemPrompt: string, userPrompt: string, schema: array<string, mixed>} $prompts
     *
     * @return array{
     *     providerId: string,
     *     modelId: string,
     *     result: array<string, mixed>,
     *     tokenUsage: array{input: ?int, output: ?int, total: ?int}
     * }
     */
    private function callStructuredChat(array $runtime, array $prompts): array
    {
        if ($this->structuredChat !== null) {
            $response = ($this->structuredChat)(
                $runtime,
                $prompts['systemPrompt'],
                $prompts['userPrompt'],
                $prompts['schema'],
            );
        } else {
            $bridge = $this->aiBridge ?? new DrupalAiBridge();
            $response = $bridge->structuredChat(
                $runtime,
                $prompts['systemPrompt'],
                $prompts['userPrompt'],
                $prompts['schema'],
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

        /** @var array{providerId: string, modelId: string, result: array<string, mixed>, tokenUsage: array{input: ?int, output: ?int, total: ?int}} $response */
        return $response;
    }
}
