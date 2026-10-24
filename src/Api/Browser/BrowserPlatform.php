<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

enum BrowserPlatform: string
{
    case WINDOWS = 'windows';
    case WSL = 'wsl';
    case LINUX = 'linux';
    case MACOS = 'macos';
}
