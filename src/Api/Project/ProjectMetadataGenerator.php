<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class ProjectMetadataGenerator
{
    /**
     * @var callable|null
     */
    private $structuredChat;

    public function __construct(?callable $structuredChat = null)
    {
        $this->structuredChat = $structuredChat;
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
     *
     * @return array{
     *     metadata: array{summary: string, description: string, features: list<string>, integrations: list<string>},
     *     providerId: string,
     *     modelId: string,
     *     tokenUsage: array{input: ?int, output: ?int, total: ?int}
     * }
     */
    public function generate(array $input): array
    {
        $builder = new ProjectMetadataPromptBuilder();
        $prompts = $builder->build($input);
        $response = $this->callStructuredChat($input['runtime'], $prompts);
        $metadata = (new ProjectMetadataResultValidator())->validate($response['result']);

        return [
            'metadata' => $metadata,
            'providerId' => $response['providerId'],
            'modelId' => $response['modelId'],
            'tokenUsage' => $response['tokenUsage'],
        ];
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
            $response = ($this->structuredChat)($runtime, $prompts['systemPrompt'], $prompts['userPrompt'], $prompts['schema']);
        } else {
            $response = (new DrupalAiBridge())->structuredChat(
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
