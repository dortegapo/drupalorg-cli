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
    public function testUsesCmdExeToLaunchUrlsFromWsl(): void
    {
        $browser = new BrowserTraitTestDouble(['rundll32.exe'], true);
        $stdErr = new BufferedOutput();
        $stdOut = new BufferedOutput();

        $browser->open('https://example.test/device', $stdErr, $stdOut);

        self::assertSame([
            ['rundll32.exe', 'https://example.test/device'],
        ], $browser->launches);
        self::assertSame("Opened: https://example.test/device\n", $stdErr->fetch());
        self::assertSame('', $stdOut->fetch());
    }

    public function testOpenUrlPrintsTheUrlWhenTheBrowserProcessFails(): void
    {
        $browser = new BrowserTraitTestDouble(['xdg-open'], false, false);
        $stdErr = new BufferedOutput();
        $stdOut = new BufferedOutput();

        $browser->open('https://example.test/device', $stdErr, $stdOut);

        self::assertSame([
            ['xdg-open', 'https://example.test/device'],
        ], $browser->launches);
        self::assertSame("Could not open a browser automatically.\n", $stdErr->fetch());
        self::assertSame("https://example.test/device\n", $stdOut->fetch());
    }

    public function testOpenUrlReportsWhenNoBrowserIsAvailable(): void
    {
        $browser = new BrowserTraitTestDouble([], false);
        $stdErr = new BufferedOutput();
        $stdOut = new BufferedOutput();

        $browser->open('https://example.test/project', $stdErr, $stdOut);

        self::assertSame("Could not open a browser automatically.\n", $stdErr->fetch());
        self::assertSame("https://example.test/project\n", $stdOut->fetch());
    }

    public function testOpenUrlCanDisplayASanitizedUrl(): void
    {
        $browser = new BrowserTraitTestDouble(['xdg-open'], false, false);
        $stdErr = new BufferedOutput();
        $stdOut = new BufferedOutput();

        $browser->open(
            'https://example.test/project#sensitive-payload',
            $stdErr,
            $stdOut,
            'https://example.test/project',
        );

        self::assertStringNotContainsString('sensitive-payload', $stdErr->fetch());
        self::assertSame("https://example.test/project\n", $stdOut->fetch());
    }
}
