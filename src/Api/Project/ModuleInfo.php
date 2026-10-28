<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/**
 * Validated metadata read from a module's .info.yml file.
 */
final class ModuleInfo
{
    /**
     * @param list<string> $dependencies
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $coreVersionRequirement,
        public readonly array $dependencies,
    ) {
    }
}
