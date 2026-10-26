<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/** A resolved module together with its validated Drupal metadata. */
final class ModuleContext
{
    public function __construct(
        public readonly ResolvedModule $location,
        public readonly ModuleInfo $info,
    ) {
    }
}
