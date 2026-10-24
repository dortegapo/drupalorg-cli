<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests;

use mglaman\DrupalOrg\Tests\Support\BrowserTraitTestDouble;
use mglaman\DrupalOrgCli\BrowserTrait;
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

    public function testLaunchBrowserReturnsTrueForAZeroExitCode(): void
    {
        $script = $this->createScript('<?php exit(0);');
        $browser = new class {
            use BrowserTrait;

            public function launch(string $browser, string $url): bool
            {
                return $this->launchBrowser($browser, $url);
            }
        };

        try {
            self::assertTrue($browser->launch(PHP_BINARY, $script));
        } finally {
            unlink($script);
        }
    }

    public function testLaunchBrowserReturnsFalseForARealNonZeroExitCodeWithOutput(): void
    {
        $script = $this->createScript("<?php fwrite(STDERR, 'browser failure\\n'); exit(7);");
        $browser = new class {
            use BrowserTrait;

            public function launch(string $browser, string $url): bool
            {
                return $this->launchBrowser($browser, $url);
            }
        };

        try {
            self::assertFalse($browser->launch(PHP_BINARY, $script));
        } finally {
            unlink($script);
        }
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
        self::assertSame('', $stdErr->fetch());
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

    private function createScript(string $contents): string
    {
        $script = sys_get_temp_dir() . '/drupalorg-browser-' . uniqid() . '.php';
        file_put_contents($script, $contents);

        return $script;
    }
}
