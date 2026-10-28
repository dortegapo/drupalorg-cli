<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

class ComposerContextLocator
{

    /**
     * Finds Composer contexts from the starting directory towards the filesystem root.
     *
     * @return iterable<array{projectRoot: string, webRoot: string}>
     *   Composer project roots and their configured web roots.
     */
    public function findCandidates(string $startDirectory): iterable
    {
        $searchDirectory = $startDirectory;
        $composerError = null;

        while (true) {
            $composerFile = $searchDirectory . DIRECTORY_SEPARATOR . 'composer.json';
            if (file_exists($composerFile)) {
                try {
                    $webRoot = $this->resolveWebRoot($searchDirectory);
                } catch (\RuntimeException $exception) {
                    $composerError ??= $exception;
                    $webRoot = null;
                }

                if ($webRoot !== null) {
                    yield [
                        'projectRoot' => $searchDirectory,
                        'webRoot' => $webRoot,
                    ];
                }
            }

            $parentDirectory = dirname($searchDirectory);
            if ($parentDirectory === $searchDirectory) {
                break;
            }

            $searchDirectory = $parentDirectory;
        }

        if ($composerError !== null) {
            throw $composerError;
        }
    }

    private function resolveWebRoot(string $projectRoot): string
    {
        $composerFile = $projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
        if (!file_exists($composerFile)) {
            return 'web';
        }

        if (!is_file($composerFile) || !is_readable($composerFile)) {
            throw new \RuntimeException(sprintf('Could not read composer.json: %s', $composerFile));
        }

        $contents = file_get_contents($composerFile);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Could not read composer.json: %s', $composerFile));
        }

        try {
            $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                sprintf('Could not parse composer.json: %s', $exception->getMessage()),
                0,
                $exception
            );
        }

        if (!is_array($composer)) {
            return 'web';
        }

        $extra = $composer['extra'] ?? null;
        if (!is_array($extra)) {
            return 'web';
        }

        $drupalScaffold = $extra['drupal-scaffold'] ?? null;
        if (!is_array($drupalScaffold)) {
            return 'web';
        }

        $locations = $drupalScaffold['locations'] ?? null;
        if (!is_array($locations)) {
            return 'web';
        }

        $webRoot = $locations['web-root'] ?? null;
        if (!is_string($webRoot) || trim($webRoot) === '') {
            return 'web';
        }

        return $webRoot;
    }
}
