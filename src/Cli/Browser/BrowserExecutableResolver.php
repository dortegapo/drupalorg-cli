<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Browser;

use Closure;
use mglaman\DrupalOrg\Browser\BrowserFamily;
use mglaman\DrupalOrg\Browser\BrowserPlatform;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;

/**
 * Builds browser probe and launch commands for the detected desktop.
 *
 * Windows paths deliberately remain small and predictable. The resolver does
 * not choose another family when the requested executable is unavailable.
 */
final class BrowserExecutableResolver
{
    /**
     * @param array<string, string|false> $environment
     */
    public function __construct(
        private readonly BrowserPlatformDetector $platformDetector,
        private readonly ?array $environment = null,
    ) {
    }

    /**
     * @return list<array{probe: list<string>, launch: Closure(string): list<string>}>
     */
    public function commands(BrowserFamily $browserFamily): array
    {
        $platform = $this->platformDetector->detect();
        if ($platform === BrowserPlatform::MACOS) {
            $application = match ($browserFamily) {
                BrowserFamily::CHROMIUM => 'Google Chrome',
                BrowserFamily::EDGE => 'Microsoft Edge',
                BrowserFamily::FIREFOX => 'Firefox',
                BrowserFamily::SAFARI => 'Safari',
            };

            return [[
                'probe' => ['open'],
                'launch' => static fn(string $url): array => ['open', '-a', $application, $url],
            ]];
        }

        if ($browserFamily === BrowserFamily::SAFARI) {
            return [];
        }

        $windows = $platform === BrowserPlatform::WINDOWS || $platform === BrowserPlatform::WSL;
        if (!$windows) {
            $executables = match ($browserFamily) {
                BrowserFamily::CHROMIUM => ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'],
                BrowserFamily::EDGE => ['microsoft-edge', 'microsoft-edge-stable'],
                BrowserFamily::FIREFOX => ['firefox'],
            };

            return array_map(static fn(string $executable): array => [
                'probe' => ['which', $executable],
                'launch' => static fn(string $url): array => [$executable, $url],
            ], $executables);
        }

        $executable = match ($browserFamily) {
            BrowserFamily::CHROMIUM => 'chrome.exe',
            BrowserFamily::EDGE => 'msedge.exe',
            BrowserFamily::FIREFOX => 'firefox.exe',
        };
        $commands = [[
            'probe' => $platform === BrowserPlatform::WSL
                ? ['cmd.exe', '/c', 'where', $executable]
                : ['where.exe', $executable],
            'launch' => $platform === BrowserPlatform::WSL
                ? static fn(string $url): array => ['cmd.exe', '/c', 'start', '', $executable, $url]
                : static fn(string $url): array => [$executable, $url],
        ]];

        foreach ($this->standardWindowsPaths($browserFamily) as $path) {
            $probe = ['cmd.exe', '/c', 'if exist "' . $path . '" exit /b 0'];
            $commands[] = [
                'probe' => $probe,
                'launch' => static fn(string $url): array => [
                    'cmd.exe',
                    '/c',
                    'start',
                    '',
                    $path,
                    $url,
                ],
            ];
        }

        return $commands;
    }

    /**
     * @return list<array{probe: list<string>, launch: Closure(string): list<string>}>
     */
    public function defaultCommands(): array
    {
        return match ($this->platformDetector->detect()) {
            BrowserPlatform::WSL => [[
                'probe' => ['cmd.exe', '/c', 'where', 'cmd.exe'],
                'launch' => static fn(string $url): array => ['cmd.exe', '/c', 'start', '', $url],
            ]],
            BrowserPlatform::WINDOWS => [[
                'probe' => ['where.exe', 'explorer.exe'],
                'launch' => static fn(string $url): array => ['explorer.exe', $url],
            ]],
            BrowserPlatform::MACOS => [[
                'probe' => ['which', 'open'],
                'launch' => static fn(string $url): array => ['open', $url],
            ]],
            BrowserPlatform::LINUX => array_map(static fn(string $executable): array => [
                'probe' => ['which', $executable],
                'launch' => static fn(string $url): array => [$executable, $url],
            ], ['xdg-open', 'open']),
        };
    }

    /**
     * @return list<string>
     */
    private function standardWindowsPaths(BrowserFamily $browserFamily): array
    {
        $paths = match ($browserFamily) {
            BrowserFamily::CHROMIUM => [
                ['Google', 'Chrome', 'Application', 'chrome.exe'],
                ['Chromium', 'Application', 'chrome.exe'],
            ],
            BrowserFamily::EDGE => [
                ['Microsoft', 'Edge', 'Application', 'msedge.exe'],
            ],
            BrowserFamily::FIREFOX => [
                ['Mozilla Firefox', 'firefox.exe'],
            ],
            BrowserFamily::SAFARI => [],
        };

        $environment = $this->environment ?? $_ENV + $_SERVER;
        $bases = [
            $this->environmentValue($environment, 'LOCALAPPDATA') ?? '%LOCALAPPDATA%',
            $this->environmentValue($environment, 'PROGRAMFILES') ?? '%PROGRAMFILES%',
            $this->environmentValue($environment, 'PROGRAMFILES(X86)')
                ?? $this->environmentValue($environment, 'ProgramFiles(x86)')
                ?? '%PROGRAMFILES(X86)%',
        ];
        $result = [];
        foreach ($bases as $base) {
            foreach ($paths as $parts) {
                $result[] = rtrim($base, '\\/') . '\\' . implode('\\', $parts);
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<string, string|false> $environment
     */
    private function environmentValue(array $environment, string $name): ?string
    {
        $value = $environment[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
