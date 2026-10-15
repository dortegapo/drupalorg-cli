<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests;

use mglaman\DrupalOrg\Tests\Support\BrowserTraitTestDouble;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversNothing]
class BrowserTraitTest extends TestCase
{
    public function testPrefersWindowsBrowserLaunchingFromWsl(): void
    {
        $browser = new BrowserTraitTestDouble(['cmd.exe', 'xdg-open'], true);

        self::assertSame('cmd.exe', $browser->defaultBrowser());
    }

    public function testUsesCmdExeToLaunchUrlsFromWsl(): void
    {
        $browser = new BrowserTraitTestDouble(['cmd.exe', 'xdg-open'], true);
        $stdErr = new BufferedOutput();
        $stdOut = new BufferedOutput();

        $browser->open('https://example.test/device', $stdErr, $stdOut);

        self::assertSame([
            ['cmd.exe', 'https://example.test/device'],
        ], $browser->launches);
        self::assertSame("Opened: https://example.test/device\n", $stdErr->fetch());
        self::assertSame('', $stdOut->fetch());
    }
}
