<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

enum BrowserCompanionInstallation: string
{
    case STORE = 'store';
    case LOCAL = 'local';
}
