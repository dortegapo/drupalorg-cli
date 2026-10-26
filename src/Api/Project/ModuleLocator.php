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
     * @return ResolvedModule
     *   The resolved module path, machine name, info file path, and optional
     *   Composer project context.
     */
    public function locate(string $modulePath): ResolvedModule
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
     * @return ResolvedModule
     */
    private function locateExplicitPath(string $modulePath): ResolvedModule
    {
        $location = $this->resolvePath($modulePath);

        try {
            foreach ($this->composerContextLocator->findCandidates($location->resolvedPath) as $context) {
                $candidate = $this->buildModulePath(
                    $context['projectRoot'],
                    $context['webRoot'],
                    $location->machineName,
                );
                if (realpath($candidate) === $location->resolvedPath) {
                    return $this->withComposerContext($location, $context);
                }
            }
        } catch (\RuntimeException) {
            // Composer context is optional for explicit paths.
        }

        return $this->withComposerContext($location);
    }

    /**
     * @return ResolvedModule
     */
    private function locateByMachineName(string $currentWorkingDirectory, string $moduleName): ResolvedModule
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
     * @return ResolvedModule
     */
    private function resolvePath(string $modulePath): ResolvedModule
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

        return new ResolvedModule($resolvedPath, $moduleName, $infoFile, null, null);
    }

    /**
     * Adds the Composer context that contains a resolved module, when known.
     *
     * @param ResolvedModule $location
     * @param array{projectRoot: string, webRoot: string}|null $context
     *
     * @return ResolvedModule
     */
    private function withComposerContext(ResolvedModule $location, ?array $context = null): ResolvedModule
    {
        return new ResolvedModule(
            $location->resolvedPath,
            $location->machineName,
            $location->infoFile,
            $context['projectRoot'] ?? null,
            $context['webRoot'] ?? null,
        );
    }
}
