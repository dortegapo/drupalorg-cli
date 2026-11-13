<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

enum BrowserFamily: string
{
    case CHROMIUM = 'chromium';
    case EDGE = 'edge';
    case FIREFOX = 'firefox';
    case SAFARI = 'safari';
}
