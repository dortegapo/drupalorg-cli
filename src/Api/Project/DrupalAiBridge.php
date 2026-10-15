<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use Symfony\Component\Process\Process;

final class DrupalAiBridge
{
    private const OUTPUT_MARKER = '__DRUPALORG_CLI_AI_CONTEXT__=';

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
}
