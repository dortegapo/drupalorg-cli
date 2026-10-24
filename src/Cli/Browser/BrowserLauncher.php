<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Browser;

use Closure;
use mglaman\DrupalOrg\Browser\BrowserFamily;
use mglaman\DrupalOrg\Browser\BrowserPlatform;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use Symfony\Component\Process\Process;

final class BrowserLauncher
{
    /**
     * @param Closure(list<string>): bool|null $commandExists
     * @param Closure(list<string>): bool|null $processRunner
     */
    public function __construct(
        private readonly BrowserPlatformDetector $platformDetector = new BrowserPlatformDetector(),
        private readonly ?Closure $commandExists = null,
        private readonly ?Closure $processRunner = null,
    ) {
    }

    public function open(BrowserFamily $browserFamily, string $url): bool
    {
        foreach ($this->commands($browserFamily) as $command) {
            if ($this->exists($command['probe'])) {
                return $this->run($command['launch']($url));
            }
        }

        return false;
    }

    public function openDefault(string $url): bool
    {
        foreach ($this->platformDetector->browserFamilies() as $browserFamily) {
            if ($this->open($browserFamily, $url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{probe: list<string>, launch: Closure(string): list<string>}>
     */
    private function commands(BrowserFamily $browserFamily): array
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

        $executables = match ($browserFamily) {
            BrowserFamily::CHROMIUM => $platform === BrowserPlatform::WSL || $platform === BrowserPlatform::WINDOWS
                ? ['chrome.exe', 'chromium.exe']
                : ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'],
            BrowserFamily::EDGE => $platform === BrowserPlatform::WSL || $platform === BrowserPlatform::WINDOWS
                ? ['msedge.exe']
                : ['microsoft-edge', 'microsoft-edge-stable'],
            BrowserFamily::FIREFOX => $platform === BrowserPlatform::WSL || $platform === BrowserPlatform::WINDOWS
                ? ['firefox.exe']
                : ['firefox'],
            BrowserFamily::SAFARI => [],
        };
        if ($executables === []) {
            return [];
        }

        $commands = [];
        foreach ($executables as $executable) {
            $probe = $platform === BrowserPlatform::WSL
                ? ['cmd.exe', '/c', 'where', $executable]
                : ($platform === BrowserPlatform::WINDOWS ? ['where.exe', $executable] : ['which', $executable]);
            $commands[] = [
                'probe' => $probe,
                'launch' => static function (string $url) use ($platform, $executable): array {
                    if ($platform === BrowserPlatform::WSL) {
                        return ['cmd.exe', '/c', 'start', '', $executable, $url];
                    }

                    return [$executable, $url];
                },
            ];
        }

        return $commands;
    }

    /**
     * @param list<string> $command
     */
    private function exists(array $command): bool
    {
        if ($this->commandExists !== null) {
            return ($this->commandExists)($command);
        }

        try {
            $process = new Process($command);
            $process->run();
        } catch (\Exception) {
            return false;
        }

        return $process->isSuccessful();
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): bool
    {
        if ($this->processRunner !== null) {
            return ($this->processRunner)($command);
        }

        try {
            $process = new Process($command);
            $process->run();
        } catch (\Exception) {
            return false;
        }

        return $process->isSuccessful();
    }
}
