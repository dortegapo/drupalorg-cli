<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use Symfony\Component\Process\Process;

final class DrupalCoreVersionInspector
{
    private const VERSION_MARKER = '__DRUPALORG_CLI_CORE_VERSION__=';

    /**
     * @param array{
     *     type: 'ddev'|'lando'|'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * } $runtime
     *
     * @return array{version: string, major: int}|null
     */
    public function inspect(array $runtime): ?array
    {
        $command = array_merge($runtime['command'], [
            'php:eval',
            'echo "' . self::VERSION_MARKER . '" . \\Drupal::VERSION;',
        ]);
        $process = new Process($command, $runtime['workingDirectory']);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $lines = preg_split('/\R/', $process->getOutput());
        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_starts_with($line, self::VERSION_MARKER)) {
                continue;
            }

            $version = substr($line, strlen(self::VERSION_MARKER));
            if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:[-+][0-9A-Za-z.-]+)?$/', $version, $matches) !== 1) {
                return null;
            }

            return [
                'version' => $version,
                'major' => (int) $matches[1],
            ];
        }

        return null;
    }
}
