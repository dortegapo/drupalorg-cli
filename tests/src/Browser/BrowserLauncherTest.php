<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Browser;

use mglaman\DrupalOrg\Browser\BrowserFamily;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use mglaman\DrupalOrgCli\Browser\BrowserExecutableResolver;
use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrowserLauncher::class)]
#[CoversClass(BrowserExecutableResolver::class)]
final class BrowserLauncherTest extends TestCase
{
    public function testWindowsLaunchesTheSelectedFirefoxDetached(): void
    {
        $commands = [];
        $launcher = $this->launcher('Windows', [], '', $commands);

        self::assertTrue($launcher->open(BrowserFamily::FIREFOX, 'https://example.test'));
        self::assertSame(['where.exe', 'firefox.exe'], $commands[0]['cmd']);
        self::assertTrue($commands[1]['run']);
        self::assertSame(['firefox.exe', 'https://example.test'], $commands[1]['cmd']);
    }

    public function testWindowsDoesNotUseAnotherBrowserWhenSelectedBrowserIsUnavailable(): void
    {
        $commands = [];
        $launcher = $this->launcher('Windows', [], '', $commands, false);

        self::assertFalse($launcher->open(BrowserFamily::FIREFOX, 'https://example.test'));
        self::assertSame([
            ['cmd' => ['where.exe', 'firefox.exe'], 'run' => false],
            ['cmd' => ['reg.exe', 'query', 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\App Paths\\firefox.exe', '/ve'], 'run' => false],
            ['cmd' => ['reg.exe', 'query', 'HKLM\\Software\\Microsoft\\Windows\\CurrentVersion\\App Paths\\firefox.exe', '/ve'], 'run' => false],
            ['cmd' => ['cmd.exe', '/d', '/s', '/c', 'if exist "%LOCALAPPDATA%\\Mozilla Firefox\\firefox.exe" (exit /b 0) else (exit /b 1)'], 'run' => false],
            ['cmd' => ['cmd.exe', '/d', '/s', '/c', 'if exist "%PROGRAMFILES%\\Mozilla Firefox\\firefox.exe" (exit /b 0) else (exit /b 1)'], 'run' => false],
            ['cmd' => ['cmd.exe', '/d', '/s', '/c', 'if exist "%PROGRAMFILES(X86)%\\Mozilla Firefox\\firefox.exe" (exit /b 0) else (exit /b 1)'], 'run' => false],
        ], $commands);
    }

    public function testWindowsUsesAStandardInstallPathWhenBrowserIsNotOnPathOrAppPaths(): void
    {
        $commands = [];
        $detector = new BrowserPlatformDetector(
            'Windows',
            ['LOCALAPPDATA' => 'C:\\Users\\Test\\AppData\\Local'],
            '',
        );
        $resolver = new BrowserExecutableResolver($detector, [
            'LOCALAPPDATA' => 'C:\\Users\\Test\\AppData\\Local',
            'PROGRAMFILES' => 'C:\\Program Files',
            'PROGRAMFILES(X86)' => 'C:\\Program Files (x86)',
        ]);
        $launcher = new BrowserLauncher(
            $detector,
            static function (array $command) use (&$commands): bool {
                $commands[] = ['cmd' => $command, 'run' => false];

                return ($command[0] ?? '') === 'cmd.exe'
                    && str_contains($command[4] ?? '', 'C:\\Users\\Test');
            },
            static function (array $command) use (&$commands): bool {
                $commands[] = ['cmd' => $command, 'run' => true];

                return true;
            },
            $resolver,
        );

        self::assertTrue($launcher->open(BrowserFamily::FIREFOX, 'https://example.test'));
        $launch = array_values(array_filter($commands, static fn(array $entry): bool => $entry['run']))[0];
        self::assertSame([
            'C:\\Users\\Test\\AppData\\Local\\Mozilla Firefox\\firefox.exe',
            'https://example.test',
        ], $launch['cmd']);
    }

    public function testWslLaunchesTheSelectedEdgeThroughWindows(): void
    {
        $commands = [];
        $launcher = $this->launcher('Linux', ['WSL_INTEROP' => '1'], '', $commands);

        self::assertTrue($launcher->open(BrowserFamily::EDGE, 'https://example.test'));
        self::assertSame(['where.exe', 'msedge.exe'], $commands[0]['cmd']);
        self::assertSame(['msedge.exe', 'https://example.test'], $commands[1]['cmd']);
    }

    public function testWslLaunchesTheSelectedFirefoxDirectly(): void
    {
        $commands = [];
        $launcher = $this->launcher('Linux', ['WSL_INTEROP' => '1'], '', $commands);

        self::assertTrue($launcher->open(BrowserFamily::FIREFOX, 'https://example.test'));
        self::assertSame(['firefox.exe', 'https://example.test'], $commands[1]['cmd']);
    }

    public function testWslLaunchesTheSelectedChromiumDirectly(): void
    {
        $commands = [];
        $launcher = $this->launcher('Linux', ['WSL_INTEROP' => '1'], '', $commands);

        self::assertTrue($launcher->open(BrowserFamily::CHROMIUM, 'https://example.test'));
        self::assertSame(['chrome.exe', 'https://example.test'], $commands[1]['cmd']);
    }

    public function testLinuxLaunchesSelectedChromiumThroughDetachedShell(): void
    {
        $commands = [];
        $launcher = $this->launcher('Linux', [], '', $commands);

        self::assertTrue($launcher->open(BrowserFamily::CHROMIUM, 'https://example.test'));
        self::assertSame(['which', 'google-chrome'], $commands[0]['cmd']);
        self::assertSame('sh', $commands[1]['cmd'][0]);
        self::assertStringContainsString('nohup', $commands[1]['cmd'][2]);
        self::assertSame('google-chrome', $commands[1]['cmd'][4]);
        self::assertSame('https://example.test', $commands[1]['cmd'][5]);
    }

    public function testMacosLaunchesSafariExplicitly(): void
    {
        $commands = [];
        $launcher = $this->launcher('Darwin', [], '', $commands);

        self::assertTrue($launcher->open(BrowserFamily::SAFARI, 'https://example.test'));
        self::assertSame(['open', '-Ra', 'Safari'], $commands[0]['cmd']);
        self::assertSame(['open', '-a', 'Safari', 'https://example.test'], $commands[1]['cmd']);
    }

    public function testLauncherTriesAnotherCompatibleBrowserWhenFirstLaunchFails(): void
    {
        $commands = [];
        $launches = 0;
        $launcher = new BrowserLauncher(
            new BrowserPlatformDetector('Linux', [], ''),
            static function (array $command) use (&$commands): bool {
                $commands[] = ['cmd' => $command, 'run' => false];

                return true;
            },
            static function (array $command) use (&$commands, &$launches): bool {
                $commands[] = ['cmd' => $command, 'run' => true];
                ++$launches;

                return $launches > 1;
            },
        );

        self::assertTrue($launcher->open(BrowserFamily::CHROMIUM, 'https://example.test'));
        self::assertSame(2, $launches);
    }

    public function testLaunchFailureReturnsFalse(): void
    {
        $launcher = new BrowserLauncher(
            new BrowserPlatformDetector('Linux', [], ''),
            static fn(array $command): bool => true,
            static fn(array $command): bool => false,
        );

        self::assertFalse($launcher->open(BrowserFamily::FIREFOX, 'https://example.test'));
    }

    public function testDefaultLauncherCanOpenACleanUrl(): void
    {
        $commands = [];
        $launcher = $this->launcher('Linux', [], '', $commands);
        $url = 'https://www.drupal.org/node/add/project-module';

        self::assertTrue($launcher->openDefaultUrl($url));
        self::assertSame(['xdg-open', $url], $commands[1]['cmd']);
    }

    public function testWslDefaultLauncherUsesRundll32WithTheUrlAsAnArgument(): void
    {
        $commands = [];
        $launcher = $this->launcher('Linux', ['WSL_INTEROP' => '1'], '', $commands);
        $url = 'https://www.drupal.org/node/add/project-module#drupalorg-cli-prefill=abc123';

        self::assertTrue($launcher->openDefaultUrl($url));
        self::assertSame(['where.exe', 'rundll32.exe'], $commands[0]['cmd']);
        self::assertSame([
            'rundll32.exe',
            'url.dll,FileProtocolHandler',
            $url,
        ], $commands[1]['cmd']);
        self::assertNotContains('cmd.exe', $commands[1]['cmd']);
        self::assertNotContains('start ""', $commands[1]['cmd']);
    }

    /**
     * @param array<int, array{cmd: list<string>, run: bool}> $commands
     */
    private function launcher(
        string $osFamily,
        array $environment,
        string $procVersion,
        array &$commands,
        bool $available = true,
    ): BrowserLauncher {
        return new BrowserLauncher(
            new BrowserPlatformDetector($osFamily, $environment, $procVersion),
            static function (array $command) use (&$commands, $available): bool {
                $commands[] = ['cmd' => $command, 'run' => false];

                return $available;
            },
            static function (array $command) use (&$commands): bool {
                $commands[] = ['cmd' => $command, 'run' => true];

                return true;
            },
        );
    }
}
