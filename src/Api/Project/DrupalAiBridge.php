<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use Symfony\Component\Process\Process;

final class DrupalAiBridge
{
    private const INVALID_RESPONSE_MESSAGE = 'Drupal AI bridge returned an invalid response.';

    private const PROVIDER_FAILURE_MESSAGE = 'Drupal AI provider invocation failed.';

    private const INVALID_STRUCTURED_OUTPUT_MESSAGE = 'Drupal AI returned invalid structured output.';

    private const OUTPUT_MARKER = '__DRUPALORG_CLI_AI_CONTEXT__=';

    private const CHAT_OUTPUT_MARKER = '__DRUPALORG_CLI_AI_RESULT__=';

    public function hasExternalProvider(): bool
    {
        return $this->externalConfig() !== null;
    }

    /**
     * Inspects the Drupal AI configuration through the resolved runtime.
     *
     * @param array{
     *     type: 'ddev'|'lando'|'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * } $runtime
     *
     * @return array{
     *     aiEnabled: bool,
     *     providerId: ?string,
     *     modelId: ?string
     * }
     */
    public function inspect(array $runtime): array
    {
        $externalConfig = $this->externalConfig();
        if ($externalConfig !== null) {
            return [
                'aiEnabled' => true,
                'providerId' => $externalConfig['providerId'],
                'modelId' => $externalConfig['modelId'],
            ];
        }

        $process = new Process(
            [...$runtime['command'], 'php:script', '-'],
            $runtime['workingDirectory']
        );
        $process->setInput($this->buildScript());
        $process->setTimeout(60.0);

        try {
            $process->run();
        } catch (\Throwable) {
            throw new \RuntimeException('Could not inspect Drupal AI configuration.');
        }

        if (!$process->isSuccessful()) {
            $exitCode = $process->getExitCode();
            $message = 'Could not inspect Drupal AI configuration.';
            if ($exitCode !== null) {
                $message = sprintf('%s (exit code %d).', rtrim($message, '.'), $exitCode);
            }

            throw new \RuntimeException($message);
        }

        return $this->parseOutput($process->getOutput());
    }

    /**
     * Executes a structured Drupal AI chat request.
     *
     * @param array{
     *     type: 'ddev'|'lando'|'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * } $runtime
     * @param string $systemPrompt
     * @param string $userPrompt
     * @param array<string, mixed> $jsonSchema
     *
     * @return array{
     *     providerId: string,
     *     modelId: string,
     *     result: array<string, mixed>,
     *     tokenUsage: array{input: ?int, output: ?int, total: ?int}
     * }
     */
    public function structuredChat(
        array $runtime,
        string $systemPrompt,
        string $userPrompt,
        array $jsonSchema,
        ?callable $onWait = null,
    ): array {
        if (trim($systemPrompt) === '') {
            throw new \InvalidArgumentException('The Drupal AI system prompt cannot be empty.');
        }

        if (trim($userPrompt) === '') {
            throw new \InvalidArgumentException('The Drupal AI user prompt cannot be empty.');
        }

        if ($jsonSchema === []) {
            throw new \InvalidArgumentException('The Drupal AI JSON schema cannot be empty.');
        }

        $externalConfig = $this->externalConfig();
        if ($externalConfig !== null) {
            return $this->externalStructuredChat(
                $externalConfig,
                $systemPrompt,
                $userPrompt,
                $jsonSchema,
            );
        }

        try {
            $encodedPayload = base64_encode(json_encode([
                'system_prompt' => $systemPrompt,
                'user_prompt' => $userPrompt,
                'schema' => $jsonSchema,
            ], JSON_THROW_ON_ERROR));
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The Drupal AI request payload is invalid.', 0, $exception);
        }

        $process = new Process(
            [...$runtime['command'], 'php:script', '-'],
            $runtime['workingDirectory']
        );
        $process->setInput($this->buildStructuredChatScript($encodedPayload));
        $process->setTimeout(120.0);

        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                if ($onWait !== null) {
                    $onWait();
                }
                usleep(100000);
            }
            $process->wait();
        } catch (\Throwable) {
            throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE);
        }

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE);
        }

        return $this->parseStructuredOutput($process->getOutput());
    }

    /**
     * @return array{endpoint: string, apiKey: string, providerId: string, modelId: string}|null
     */
    private function externalConfig(): ?array
    {
        $type = getenv('DRUPALORG_AI_PROVIDER_TYPE');
        if ($type !== 'openai-compatible') {
            return null;
        }

        $apiKey = getenv('DRUPALORG_AI_API_KEY');
        $model = getenv('DRUPALORG_AI_MODEL');
        $endpoint = getenv('DRUPALORG_AI_ENDPOINT');
        if (!is_string($apiKey) || trim($apiKey) === ''
            || !is_string($model) || trim($model) === ''
            || !is_string($endpoint) || trim($endpoint) === ''
        ) {
            return null;
        }

        $endpoint = rtrim(trim($endpoint), '/');
        if (!str_ends_with($endpoint, '/chat/completions')) {
            $endpoint .= '/chat/completions';
        }

        $providerName = getenv('DRUPALORG_AI_PROVIDER_NAME');
        $providerId = is_string($providerName) && trim($providerName) !== ''
            ? trim($providerName)
            : 'openai-compatible';

        return [
            'endpoint' => $endpoint,
            'apiKey' => trim($apiKey),
            'providerId' => $providerId,
            'modelId' => trim($model),
        ];
    }

    /**
     * @param array{endpoint: string, apiKey: string, providerId: string, modelId: string} $config
     * @param array<string, mixed> $jsonSchema
     *
     * @return array{
     *     providerId: string,
     *     modelId: string,
     *     result: array<string, mixed>,
     *     tokenUsage: array{input: ?int, output: ?int, total: ?int}
     * }
     */
    private function externalStructuredChat(
        array $config,
        string $systemPrompt,
        string $userPrompt,
        array $jsonSchema,
    ): array {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $config['apiKey'],
            'Content-Type' => 'application/json',
        ];
        $organization = getenv('DRUPALORG_AI_ORGANIZATION');
        if (is_string($organization) && trim($organization) !== '') {
            $headers['OpenAI-Organization'] = trim($organization);
        }

        try {
            $response = (new \GuzzleHttp\Client())->request('POST', $config['endpoint'], [
                'headers' => $headers,
                'timeout' => 120.0,
                'json' => [
                    'model' => $config['modelId'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'drupalorg_cli_response',
                            'strict' => true,
                            'schema' => $jsonSchema,
                        ],
                    ],
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $content = $payload['choices'][0]['message']['content'] ?? null;
            if (!is_string($content)) {
                throw new \RuntimeException('The provider returned no message content.');
            }
            $resultObject = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
            if (!is_object($resultObject)) {
                throw new \RuntimeException('The provider returned invalid structured output.');
            }
            /** @var array<string, mixed> $result */
            $result = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
            $input = is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : null;
            $output = is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : null;
            $total = is_int($usage['total_tokens'] ?? null) ? $usage['total_tokens'] : null;

            return [
                'providerId' => $config['providerId'],
                'modelId' => $config['modelId'],
                'result' => $result,
                'tokenUsage' => ['input' => $input, 'output' => $output, 'total' => $total],
            ];
        } catch (\Throwable) {
            throw new \RuntimeException(self::PROVIDER_FAILURE_MESSAGE);
        }
    }

    private function buildScript(): string
    {
        return <<<'PHP'
$aiEnabled = Drupal::moduleHandler()->moduleExists('ai');
$providerId = null;
$modelId = null;

if ($aiEnabled) {
    $defaults = Drupal::service('ai.provider')->getDefaultProviderForOperationType('chat_with_structured_response');
    if (is_array($defaults)) {
        $providerId = $defaults['provider_id'] ?? null;
        $modelId = $defaults['model_id'] ?? null;
        $providerId = is_string($providerId) && $providerId !== '' ? $providerId : null;
        $modelId = is_string($modelId) && $modelId !== '' ? $modelId : null;
    }
}

echo '__DRUPALORG_CLI_AI_CONTEXT__=' . json_encode([
    'ai_enabled' => $aiEnabled,
    'provider_id' => $providerId,
    'model_id' => $modelId,
], JSON_THROW_ON_ERROR) . PHP_EOL;
PHP;
    }

    private function buildStructuredChatScript(string $encodedPayload): string
    {
        return sprintf(
            <<<'PHP'
$payloadJson = base64_decode(%s, true);
if ($payloadJson === false) {
    throw new \RuntimeException('Could not decode the Drupal AI request payload.');
}

$payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
$emit = static function (array $envelope): void {
    echo %s . json_encode($envelope, JSON_THROW_ON_ERROR) . PHP_EOL;
};

$aiEnabled = \Drupal::moduleHandler()->moduleExists('ai');
if (!$aiEnabled) {
    $emit([
        'ok' => false,
        'error' => 'ai_disabled',
    ]);
    return;
}

try {
    $manager = \Drupal::service('ai.provider');
    $default = $manager->getDefaultProviderForOperationType('chat_with_structured_response');
    if (!is_array($default)
        || !isset($default['provider_id'], $default['model_id'])
        || !is_string($default['provider_id'])
        || $default['provider_id'] === ''
        || !is_string($default['model_id'])
        || $default['model_id'] === ''
    ) {
        $emit([
            'ok' => false,
            'error' => 'no_structured_provider',
        ]);
        return;
    }

    $provider = $manager->createInstance($default['provider_id']);
    $schema = new \Drupal\ai\Dto\StructuredOutputSchema(
        name: 'drupalorg_cli_response',
        description: 'Structured response requested by drupalorg-cli.',
        strict: true,
        json_schema: $payload['schema'],
    );
    $input = new \Drupal\ai\OperationType\Chat\ChatInput([
        new \Drupal\ai\OperationType\Chat\ChatMessage(
            'system',
            $payload['system_prompt']
        ),
        new \Drupal\ai\OperationType\Chat\ChatMessage(
            'user',
            $payload['user_prompt']
        ),
    ]);
    $input->setChatStructuredJsonSchema($schema);
    $response = $provider->chat(
        $input,
        $default['model_id'],
        ['drupalorg_cli']
    );
} catch (\Throwable) {
    $emit([
        'ok' => false,
        'error' => 'provider_failure',
    ]);
    return;
}

try {
    $resultText = $response->getNormalized()->getText();
} catch (\Throwable) {
    $emit([
        'ok' => false,
        'error' => 'provider_failure',
    ]);
    return;
}

if (!is_string($resultText)) {
    $emit([
        'ok' => false,
        'error' => 'invalid_structured_output',
    ]);
    return;
}

try {
    $result = json_decode($resultText, false, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    $emit([
        'ok' => false,
        'error' => 'invalid_structured_output',
    ]);
    return;
}

if (!is_object($result)) {
    $emit([
        'ok' => false,
        'error' => 'invalid_structured_output',
    ]);
    return;
}

$tokenUsage = [
    'input' => null,
    'output' => null,
    'total' => null,
];

try {
    $usage = $response->getTokenUsage();
    if (is_object($usage)) {
        $tokenUsage = [
            'input' => is_int($usage->input ?? null) ? $usage->input : null,
            'output' => is_int($usage->output ?? null) ? $usage->output : null,
            'total' => is_int($usage->total ?? null) ? $usage->total : null,
        ];
    }
} catch (\Throwable) {
    // Token usage is observability metadata and must not invalidate a valid response.
}

$emit([
    'ok' => true,
    'provider_id' => $default['provider_id'],
    'model_id' => $default['model_id'],
    'result' => $result,
    'token_usage' => $tokenUsage,
]);
PHP,
            var_export($encodedPayload, true),
            var_export(self::CHAT_OUTPUT_MARKER, true)
        );
    }

    /**
     * @return array{
     *     aiEnabled: bool,
     *     providerId: ?string,
     *     modelId: ?string
     * }
     */
    private function parseOutput(string $output): array
    {
        $lines = preg_split('/\R/', $output);
        if ($lines === false) {
            throw new \RuntimeException('Drupal AI bridge returned an invalid response.');
        }

        foreach ($lines as $line) {
            if (!str_starts_with($line, self::OUTPUT_MARKER)) {
                continue;
            }

            try {
                $payload = json_decode(
                    substr($line, strlen(self::OUTPUT_MARKER)),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException) {
                throw new \RuntimeException('Drupal AI bridge returned an invalid response.');
            }

            if (!is_array($payload)
                || !array_key_exists('ai_enabled', $payload)
                || !is_bool($payload['ai_enabled'])
                || !array_key_exists('provider_id', $payload)
                || !is_string($payload['provider_id']) && $payload['provider_id'] !== null
                || !array_key_exists('model_id', $payload)
                || !is_string($payload['model_id']) && $payload['model_id'] !== null
            ) {
                throw new \RuntimeException('Drupal AI bridge returned an invalid response.');
            }

            return [
                'aiEnabled' => $payload['ai_enabled'],
                'providerId' => $payload['provider_id'] === '' ? null : $payload['provider_id'],
                'modelId' => $payload['model_id'] === '' ? null : $payload['model_id'],
            ];
        }

        throw new \RuntimeException('Drupal AI bridge returned an invalid response.');
    }

    /**
     * @return array{
     *     providerId: string,
     *     modelId: string,
     *     result: array<string, mixed>,
     *     tokenUsage: array{input: ?int, output: ?int, total: ?int}
     * }
     */
    private function parseStructuredOutput(string $output): array
    {
        $lines = preg_split('/\R/', $output);
        if ($lines === false) {
            throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE);
        }

        foreach ($lines as $line) {
            if (!str_starts_with($line, self::CHAT_OUTPUT_MARKER)) {
                continue;
            }

            try {
                $payloadJson = substr($line, strlen(self::CHAT_OUTPUT_MARKER));
                $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
                $payloadObject = json_decode($payloadJson, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE);
            }

            if (!is_array($payload)
                || !is_object($payloadObject)
                || !array_key_exists('ok', $payload)
                || !is_bool($payload['ok'])
            ) {
                throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE);
            }

            if (!$payload['ok']) {
                $error = $payload['error'] ?? null;
                if ($error === 'ai_disabled') {
                    throw new \RuntimeException('Drupal AI is not enabled in the target Drupal project.');
                }
                if ($error === 'no_structured_provider') {
                    throw new \RuntimeException(
                        'Drupal AI has no default structured chat provider/model configured.'
                    );
                }
                if ($error === 'provider_failure') {
                    throw new \RuntimeException(self::PROVIDER_FAILURE_MESSAGE);
                }
                if ($error === 'invalid_structured_output') {
                    throw new \RuntimeException(self::INVALID_STRUCTURED_OUTPUT_MESSAGE);
                }

                throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE);
            }

            if (!$this->isValidStructuredSuccess($payload)) {
                throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE);
            }

            if (!property_exists($payloadObject, 'result') || !is_object($payloadObject->result)) {
                throw new \RuntimeException(self::INVALID_STRUCTURED_OUTPUT_MESSAGE);
            }

            /** @var array<string, mixed> $result */
            $result = $payload['result'];
            /** @var array{input: ?int, output: ?int, total: ?int} $tokenUsage */
            $tokenUsage = $payload['token_usage'];

            return [
                'providerId' => $payload['provider_id'],
                'modelId' => $payload['model_id'],
                'result' => $result,
                'tokenUsage' => $tokenUsage,
            ];
        }

        throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isValidStructuredSuccess(array $payload): bool
    {
        if (!array_key_exists('provider_id', $payload)
            || !is_string($payload['provider_id'])
            || $payload['provider_id'] === ''
            || !array_key_exists('model_id', $payload)
            || !is_string($payload['model_id'])
            || $payload['model_id'] === ''
            || !array_key_exists('result', $payload)
            || !array_key_exists('token_usage', $payload)
            || !is_array($payload['token_usage'])
        ) {
            return false;
        }

        $tokenUsage = $payload['token_usage'];
        foreach (['input', 'output', 'total'] as $key) {
            if (!array_key_exists($key, $tokenUsage)
                || (!is_int($tokenUsage[$key]) && $tokenUsage[$key] !== null)
            ) {
                return false;
            }
        }

        return true;
    }
}
