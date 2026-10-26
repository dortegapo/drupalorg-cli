<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Result\ResultInterface;

/** Result of resolving and validating a local Drupal module. */
final class ResolveModuleResult implements ResultInterface
{
    public function __construct(
        public readonly ModuleContext $module,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'resolved_path' => $this->module->location->resolvedPath,
            'machine_name' => $this->module->location->machineName,
            'info_file' => $this->module->location->infoFile,
            'project_root' => $this->module->location->projectRoot,
            'web_root' => $this->module->location->webRoot,
            'name' => $this->module->info->name,
            'type' => $this->module->info->type,
            'core_version_requirement' => $this->module->info->coreVersionRequirement,
            'dependencies' => $this->module->info->dependencies,
        ];
    }
}
