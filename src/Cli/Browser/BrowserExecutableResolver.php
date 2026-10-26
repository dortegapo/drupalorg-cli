<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Browser;

use Closure;
use mglaman\DrupalOrg\Browser\BrowserFamily;
use mglaman\DrupalOrg\Browser\BrowserPlatform;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;

/**
 * Builds browser probe and detached launch commands for the detected desktop.
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
            return array_map(
                static fn(string $application): array => [
                    'probe' => ['open', '-Ra', $application],
                    'launch' => static fn(string $url): array => ['open', '-a', $application, $url],
                ],
                $this->macApplications($browserFamily),
            );
        }

        if ($browserFamily === BrowserFamily::SAFARI) {
            return [];
        }

        if ($platform === BrowserPlatform::WINDOWS || $platform === BrowserPlatform::WSL) {
            return $this->windowsCommands($browserFamily);
        }

        return array_map(
            static fn(string $executable): array => [
                'probe' => ['which', $executable],
                'launch' => static fn(string $url): array => [
                    'sh',
                    '-c',
                    'nohup "$1" "$2" >/dev/null 2>&1 < /dev/null &',
                    'drupalorg-cli-browser',
                    $executable,
                    $url,
                ],
            ],
            $this->linuxExecutables($browserFamily),
        );
    }

    /**
     * @return list<array{probe: list<string>, launch: Closure(string): list<string>}>
     */
    public function defaultCommands(): array
    {
        return match ($this->platformDetector->detect()) {
            BrowserPlatform::WSL,
            BrowserPlatform::WINDOWS => [[
                'probe' => ['where.exe', 'rundll32.exe'],
                'launch' => static fn(string $url): array => [
                    'rundll32.exe',
                    'url.dll,FileProtocolHandler',
                    $url,
                ],
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
     * @return list<array{probe: list<string>, launch: Closure(string): list<string>}>
     */
    private function windowsCommands(BrowserFamily $browserFamily): array
    {
        $platform = $this->platformDetector->detect();
        $commands = [];
        foreach ($this->windowsExecutables($browserFamily) as $executable) {
            $commands[] = [
                'probe' => ['where.exe', $executable],
                'launch' => $platform === BrowserPlatform::WSL
                    ? static fn(string $url): array => [
                        'cmd.exe',
                        '/c',
                        'start',
                        '',
                        $executable,
                        $url,
                    ]
                    : static fn(string $url): array => self::windowsShellExecuteCommand($executable, $url),
            ];

            foreach (['HKCU', 'HKLM'] as $hive) {
                $registryPath = $hive
                    . '\\Software\\Microsoft\\Windows\\CurrentVersion\\App Paths\\'
                    . $executable;
                $commands[] = [
                    'probe' => ['reg.exe', 'query', $registryPath, '/ve'],
                    'launch' => $platform === BrowserPlatform::WSL
                        ? static fn(string $url): array => [
                            'cmd.exe',
                            '/c',
                            'start',
                            '',
                            $executable,
                            $url,
                        ]
                        : static fn(string $url): array => self::windowsShellExecuteCommand($executable, $url),
                ];
            }
        }

        foreach ($this->standardWindowsPaths($browserFamily) as $path) {
            $escapedPath = self::escapeCmdQuotedValue($path);
            $commands[] = [
                'probe' => [
                    'cmd.exe',
                    '/d',
                    '/s',
                    '/c',
                    'if exist "' . $escapedPath . '" (exit /b 0) else (exit /b 1)',
                ],
                'launch' => $platform === BrowserPlatform::WSL
                    ? static fn(string $url): array => [
                        'cmd.exe',
                        '/c',
                        'start',
                        '',
                        $path,
                        $url,
                    ]
                    : static fn(string $url): array => self::windowsShellExecuteCommand($path, $url),
            ];
        }

        return $commands;
    }

    /** @return list<string> */
    private function linuxExecutables(BrowserFamily $browserFamily): array
    {
        return match ($browserFamily) {
            BrowserFamily::CHROMIUM => [
                'google-chrome',
                'google-chrome-stable',
                'chromium',
                'chromium-browser',
                'brave-browser',
                'vivaldi',
                'opera',
            ],
            BrowserFamily::EDGE => ['microsoft-edge', 'microsoft-edge-stable'],
            BrowserFamily::FIREFOX => ['firefox'],
            BrowserFamily::SAFARI => [],
        };
    }

    /** @return list<string> */
    private function windowsExecutables(BrowserFamily $browserFamily): array
    {
        return match ($browserFamily) {
            BrowserFamily::CHROMIUM => [
                'chrome.exe',
                'chromium.exe',
                'brave.exe',
                'vivaldi.exe',
                'opera.exe',
            ],
            BrowserFamily::EDGE => ['msedge.exe'],
            BrowserFamily::FIREFOX => ['firefox.exe'],
            BrowserFamily::SAFARI => [],
        };
    }

    /** @return list<string> */
    private function macApplications(BrowserFamily $browserFamily): array
    {
        return match ($browserFamily) {
            BrowserFamily::CHROMIUM => [
                'Google Chrome',
                'Chromium',
                'Brave Browser',
                'Vivaldi',
                'Opera',
            ],
            BrowserFamily::EDGE => ['Microsoft Edge'],
            BrowserFamily::FIREFOX => ['Firefox'],
            BrowserFamily::SAFARI => ['Safari'],
        };
    }

    /** @return list<string> */
    private function standardWindowsPaths(BrowserFamily $browserFamily): array
    {
        $paths = match ($browserFamily) {
            BrowserFamily::CHROMIUM => [
                ['Google', 'Chrome', 'Application', 'chrome.exe'],
                ['Chromium', 'Application', 'chromium.exe'],
                ['BraveSoftware', 'Brave-Browser', 'Application', 'brave.exe'],
                ['Vivaldi', 'Application', 'vivaldi.exe'],
                ['Programs', 'Opera', 'launcher.exe'],
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

    /** @param array<string, string|false> $environment */
    private function environmentValue(array $environment, string $name): ?string
    {
        $value = $environment[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function escapeCmdQuotedValue(string $value): string
    {
        return str_replace('"', '""', $value);
    }

    /** @return list<string> */
    private static function windowsShellExecuteCommand(string $executable, string $url): array
    {
        return [
            'rundll32.exe',
            'shell32.dll,ShellExec_RunDLL',
            $executable,
            $url,
        ];
    }
}
