<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ModuleInfoReader;
use mglaman\DrupalOrg\Project\ModuleLocator;
use mglaman\DrupalOrg\Result\Project\ResolveModuleResult;

final class ResolveModuleAction implements ActionInterface
{
    public function __construct(
        private readonly ModuleLocator $locator,
        private readonly ModuleInfoReader $infoReader,
    ) {
    }

    public function __invoke(string $module): ResolveModuleResult
    {
        $location = $this->locator->locate($module);
        $info = $this->infoReader->read($location->infoFile, $location->machineName);

        return new ResolveModuleResult(new ModuleContext($location, $info));
    }
}
