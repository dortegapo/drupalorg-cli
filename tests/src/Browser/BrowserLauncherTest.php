<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Browser;

use mglaman\DrupalOrg\Browser\BrowserFamily;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrowserLauncher::class)]
final class BrowserLauncherTest extends TestCase
{
    public function testWindowsLaunchesTheSelectedFirefox(): void
    {
        $commands = [];
        $launcher = $this->launcher('Windows', [], '', $commands);

        self::assertTrue($launcher->open(BrowserFamily::FIREFOX, 'https://example.test'));
        self::assertSame([
            ['cmd' => ['where.exe', 'firefox.exe'], 'run' => false],
            ['cmd' => ['firefox.exe', 'https://example.test'], 'run' => true],
        ], $commands);
    }

    public function testWindowsDoesNotUseAnotherBrowserWhenSelectedBrowserIsUnavailable(): void
    {
        $commands = [];
        $launcher = $this->launcher('Windows', [], '', $commands, false);

        self::assertFalse($launcher->open(BrowserFamily::FIREFOX, 'https://example.test'));
        self::assertSame([
            ['cmd' => ['where.exe', 'firefox.exe'], 'run' => false],
        ], $commands);
    }

    public function testWslLaunchesTheSelectedEdgeThroughWindows(): void
    {
        $commands = [];
        $launcher = $this->launcher('Linux', ['WSL_INTEROP' => '1'], '', $commands);

        self::assertTrue($launcher->open(BrowserFamily::EDGE, 'https://example.test'));
        self::assertSame([
            ['cmd' => ['cmd.exe', '/c', 'where', 'msedge.exe'], 'run' => false],
            ['cmd' => ['cmd.exe', '/c', 'start', '', 'msedge.exe', 'https://example.test'], 'run' => true],
        ], $commands);
    }

    public function testLinuxLaunchesChromium(): void
    {
        $commands = [];
        $launcher = $this->launcher('Linux', [], '', $commands);

        self::assertTrue($launcher->open(BrowserFamily::CHROMIUM, 'https://example.test'));
        self::assertSame([
            ['cmd' => ['which', 'google-chrome'], 'run' => false],
            ['cmd' => ['google-chrome', 'https://example.test'], 'run' => true],
        ], $commands);
    }

    public function testMacosLaunchesSafariExplicitly(): void
    {
        $commands = [];
        $launcher = $this->launcher('Darwin', [], '', $commands);

        self::assertTrue($launcher->open(BrowserFamily::SAFARI, 'https://example.test'));
        self::assertSame([
            ['cmd' => ['open'], 'run' => false],
            ['cmd' => ['open', '-a', 'Safari', 'https://example.test'], 'run' => true],
        ], $commands);
    }

    public function testDefaultLauncherCanOpenACleanUrlWithoutChangingConfiguredFamily(): void
    {
        $commands = [];
        $launcher = $this->launcher('Linux', [], '', $commands);
        $url = 'https://www.drupal.org/node/add/project-module';

        self::assertTrue($launcher->openDefault($url));
        self::assertSame($url, $commands[1]['cmd'][1]);
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
