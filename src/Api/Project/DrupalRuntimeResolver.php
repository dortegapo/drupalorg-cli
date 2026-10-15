<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use Symfony\Component\Process\ExecutableFinder;

class DrupalRuntimeResolver
{
    public function __construct(
        private readonly ?ExecutableFinder $executableFinder = null,
    ) {
    }

    /**
     * Resolves a command for running Drush in the project context.
     *
     * @return array{
     *     type: 'ddev'|'lando'|'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * }|null
     */
    public function resolve(?string $projectRoot): ?array
    {
        if ($projectRoot === null) {
            return null;
        }

        $executableFinder = $this->executableFinder ?? new ExecutableFinder();

        if (is_file($projectRoot . DIRECTORY_SEPARATOR . '.ddev' . DIRECTORY_SEPARATOR . 'config.yaml')) {
            $ddevExecutable = $executableFinder->find('ddev');
            if ($ddevExecutable !== null) {
                return [
                    'type' => 'ddev',
                    'workingDirectory' => $projectRoot,
                    'command' => [$ddevExecutable, 'drush'],
                ];
            }
        }

        if (is_file($projectRoot . DIRECTORY_SEPARATOR . '.lando.yml')) {
            $landoExecutable = $executableFinder->find('lando');
            if ($landoExecutable !== null) {
                return [
                    'type' => 'lando',
                    'workingDirectory' => $projectRoot,
                    'command' => [$landoExecutable, 'drush'],
                ];
            }
        }

        $localDrush = $projectRoot
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'drush';
        if (is_file($localDrush) && is_executable($localDrush)) {
            return [
                'type' => 'local-drush',
                'workingDirectory' => $projectRoot,
                'command' => [$localDrush],
            ];
        }

        return null;
    }
}
