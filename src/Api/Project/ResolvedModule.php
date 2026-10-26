<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/**
 * Physical module location resolved by ModuleLocator.
 */
final class ResolvedModule
{
    public function __construct(
        public readonly string $resolvedPath,
        public readonly string $machineName,
        public readonly string $infoFile,
        public readonly ?string $projectRoot,
        public readonly ?string $webRoot,
    ) {
    }
}
