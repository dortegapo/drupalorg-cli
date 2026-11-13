<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

enum BrowserCompanionSetupStatus: string
{
    case CONFIGURED = 'configured';
    case ALREADY_CONFIGURED = 'already_configured';
    case MANUAL_SELECTED = 'manual_selected';
    case DISTRIBUTION_UNAVAILABLE = 'distribution_unavailable';
    case LAUNCH_FAILED = 'launch_failed';
    case SAVE_FAILED = 'save_failed';
}
