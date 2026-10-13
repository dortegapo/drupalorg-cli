<?php

namespace mglaman\DrupalOrg\Project;

class ModuleLocator
{

    /**
     * Locates a module from an explicit filesystem path.
     *
     * @return array{resolvedPath: string, moduleName: string, infoFile: string}
     *   The resolved module path, module name, and info file path.
     */
    public function locate(string $modulePath): array
    {
        if (!file_exists($modulePath) && !$this->isExplicitPath($modulePath)) {
            $currentWorkingDirectory = getcwd();
            if ($currentWorkingDirectory === false) {
                throw new \RuntimeException('Could not determine the current working directory.');
            }

            return $this->locateByMachineName($currentWorkingDirectory, $modulePath);
        }

        return $this->resolvePath($modulePath);
    }

    private function isExplicitPath(string $modulePath): bool
    {
        return str_starts_with($modulePath, '.') || str_contains($modulePath, DIRECTORY_SEPARATOR);
    }

    private function resolveWebRoot(string $currentWorkingDirectory): string
    {
        $composerFile = $currentWorkingDirectory . DIRECTORY_SEPARATOR . 'composer.json';
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

    /**
     * @return array{resolvedPath: string, moduleName: string, infoFile: string}
     */
    private function locateByMachineName(string $currentWorkingDirectory, string $moduleName): array
    {
        $searchDirectory = $currentWorkingDirectory;
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
                    $candidate = $this->buildModulePath($searchDirectory, $webRoot, $moduleName);
                    if (is_dir($candidate)) {
                        return $this->resolvePath($candidate);
                    }
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

        return $this->resolvePath($this->buildModulePath($currentWorkingDirectory, 'web', $moduleName));
    }

    private function buildModulePath(string $projectRoot, string $webRoot, string $moduleName): string
    {
        return $projectRoot
            . DIRECTORY_SEPARATOR . rtrim($webRoot, '/\\')
            . DIRECTORY_SEPARATOR . 'modules'
            . DIRECTORY_SEPARATOR . 'custom'
            . DIRECTORY_SEPARATOR . $moduleName;
    }

    /**
     * @return array{resolvedPath: string, moduleName: string, infoFile: string}
     */
    private function resolvePath(string $modulePath): array
    {
        if (!file_exists($modulePath)) {
            throw new \RuntimeException(sprintf('Module path does not exist: %s', $modulePath));
        }

        if (!is_dir($modulePath)) {
            throw new \RuntimeException(sprintf('Module path is not a directory: %s', $modulePath));
        }

        $resolvedPath = realpath($modulePath);
        if ($resolvedPath === false) {
            throw new \RuntimeException(sprintf('Could not resolve module path: %s', $modulePath));
        }

        $moduleName = basename($resolvedPath);
        $infoFile = $resolvedPath . DIRECTORY_SEPARATOR . $moduleName . '.info.yml';
        if (!is_file($infoFile)) {
            throw new \RuntimeException(sprintf('Module info file does not exist: %s', $infoFile));
        }

        return [
            'resolvedPath' => $resolvedPath,
            'moduleName' => $moduleName,
            'infoFile' => $infoFile,
        ];
    }
}
