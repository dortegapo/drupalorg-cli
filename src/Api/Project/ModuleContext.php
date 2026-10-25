<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/**
 * A resolved module together with its validated Drupal metadata.
 */
final class ModuleContext
{
    public readonly string $resolvedPath;
    public readonly string $machineName;
    public readonly string $infoFile;
    public readonly ?string $projectRoot;
    public readonly ?string $webRoot;
    public readonly string $humanName;
    public readonly string $extensionType;
    public readonly string $coreVersionRequirement;

    /** @var list<string> */
    public readonly array $dependencies;

    public function __construct(
        public readonly ResolvedModule $location,
        public readonly ModuleInfo $info,
    ) {
        $this->resolvedPath = $location->resolvedPath;
        $this->machineName = $location->machineName;
        $this->infoFile = $location->infoFile;
        $this->projectRoot = $location->projectRoot;
        $this->webRoot = $location->webRoot;
        $this->humanName = $info->name;
        $this->extensionType = $info->type;
        $this->coreVersionRequirement = $info->coreVersionRequirement;
        $this->dependencies = $info->dependencies;
    }

    public function humanName(): string
    {
        return $this->humanName;
    }

    public function extensionType(): string
    {
        return $this->extensionType;
    }

    public function coreVersionRequirement(): string
    {
        return $this->coreVersionRequirement;
    }

    /**
     * @return list<string>
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }
}
