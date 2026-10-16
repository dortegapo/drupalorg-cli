<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use Symfony\Component\Process\Process;

final class DrupalCoreVersionInspector
{
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
            'echo \\Drupal::VERSION;',
        ]);
        $process = new Process($command, $runtime['workingDirectory']);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $version = trim($process->getOutput());
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:[-+][0-9A-Za-z.-]+)?$/', $version, $matches) !== 1) {
            return null;
        }

        return [
            'version' => $version,
            'major' => (int) $matches[1],
        ];
    }
}
