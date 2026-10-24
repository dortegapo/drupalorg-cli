<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

class ModuleLocator
{

    public function __construct(
        private readonly ComposerContextLocator $composerContextLocator,
    ) {
    }

    /**
     * Locates a module from an explicit path or machine name.
     *
     * @return array{
     *     resolvedPath: string,
     *     moduleName: string,
     *     infoFile: string,
     *     projectRoot: ?string,
     *     webRoot: ?string
     * }
     *   The resolved module path, module name, info file path, and optional
     *   Composer project context.
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

        return $this->locateExplicitPath($modulePath);
    }

    private function isExplicitPath(string $modulePath): bool
    {
        return str_starts_with($modulePath, '.') || str_contains($modulePath, DIRECTORY_SEPARATOR);
    }

    /**
     * Resolves an explicit module path and finds its Composer context, when known.
     *
     * @return array{
     *     resolvedPath: string,
     *     moduleName: string,
     *     infoFile: string,
     *     projectRoot: ?string,
     *     webRoot: ?string
     * }
     */
    private function locateExplicitPath(string $modulePath): array
    {
        $location = $this->resolvePath($modulePath);

        try {
            foreach ($this->composerContextLocator->findCandidates($location['resolvedPath']) as $context) {
                $candidate = $this->buildModulePath(
                    $context['projectRoot'],
                    $context['webRoot'],
                    $location['moduleName']
                );
                if (realpath($candidate) === $location['resolvedPath']) {
                    return $this->withComposerContext($location, $context);
                }
            }
        } catch (\RuntimeException) {
            // Composer context is optional for explicit paths.
        }

        return $this->withComposerContext($location);
    }

    /**
     * @return array{
     *     resolvedPath: string,
     *     moduleName: string,
     *     infoFile: string,
     *     projectRoot: ?string,
     *     webRoot: ?string
     * }
     */
    private function locateByMachineName(string $currentWorkingDirectory, string $moduleName): array
    {
        foreach ($this->composerContextLocator->findCandidates($currentWorkingDirectory) as $context) {
            $candidate = $this->buildModulePath($context['projectRoot'], $context['webRoot'], $moduleName);
            $infoFile = $candidate . DIRECTORY_SEPARATOR . $moduleName . '.info.yml';
            if (is_dir($candidate) && is_file($infoFile)) {
                return $this->withComposerContext($this->resolvePath($candidate), $context);
            }
        }

        return $this->withComposerContext(
            $this->resolvePath($this->buildModulePath($currentWorkingDirectory, 'web', $moduleName))
        );
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

    /**
     * Adds the Composer context that contains a resolved module, when known.
     *
     * @param array{resolvedPath: string, moduleName: string, infoFile: string} $location
     * @param array{projectRoot: string, webRoot: string}|null $context
     *
     * @return array{
     *     resolvedPath: string,
     *     moduleName: string,
     *     infoFile: string,
     *     projectRoot: ?string,
     *     webRoot: ?string
     * }
     */
    private function withComposerContext(array $location, ?array $context = null): array
    {
        return [
            'resolvedPath' => $location['resolvedPath'],
            'moduleName' => $location['moduleName'],
            'infoFile' => $location['infoFile'],
            'projectRoot' => $context['projectRoot'] ?? null,
            'webRoot' => $context['webRoot'] ?? null,
        ];
    }
}
