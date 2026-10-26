<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Browser;

use Closure;
use mglaman\DrupalOrg\Browser\BrowserFamily;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use Symfony\Component\Process\Process;

final class BrowserLauncher
{
    private const PROBE_TIMEOUT = 5.0;

    private const LAUNCH_TIMEOUT = 10.0;

    /**
     * @param Closure(list<string>): bool|null $commandExists
     * @param Closure(list<string>): bool|null $processRunner
     */
    public function __construct(
        private readonly BrowserPlatformDetector $platformDetector = new BrowserPlatformDetector(),
        private readonly ?Closure $commandExists = null,
        private readonly ?Closure $processRunner = null,
        private readonly ?BrowserExecutableResolver $executableResolver = null,
    ) {
    }

    public function open(BrowserFamily $browserFamily, string $url): bool
    {
        foreach ($this->commands($browserFamily) as $command) {
            if ($this->exists($command['probe']) && $this->run($command['launch']($url))) {
                return true;
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

    public function openDefaultUrl(string $url): bool
    {
        $resolver = $this->executableResolver ?? new BrowserExecutableResolver($this->platformDetector);
        foreach ($resolver->defaultCommands() as $command) {
            if ($this->exists($command['probe']) && $this->run($command['launch']($url))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{probe: list<string>, launch: Closure(string): list<string>}> */
    private function commands(BrowserFamily $browserFamily): array
    {
        return ($this->executableResolver ?? new BrowserExecutableResolver($this->platformDetector))
            ->commands($browserFamily);
    }

    /** @param list<string> $command */
    private function exists(array $command): bool
    {
        if ($this->commandExists !== null) {
            return ($this->commandExists)($command);
        }

        try {
            $process = new Process($command);
            $process->setTimeout(self::PROBE_TIMEOUT);
            $process->run();
        } catch (\Exception) {
            return false;
        }

        return $process->isSuccessful();
    }

    /**
     * Launch commands are wrappers that detach the actual browser process:
     * Windows uses ShellExecute via rundll32, WSL uses cmd.exe/start,
     * LaunchServices on macOS, and nohup for an explicitly selected Linux
     * browser.
     *
     * @param list<string> $command
     */
    private function run(array $command): bool
    {
        if ($this->processRunner !== null) {
            return ($this->processRunner)($command);
        }

        try {
            $process = new Process($command);
            $process->setTimeout(self::LAUNCH_TIMEOUT);
            $process->run();
        } catch (\Exception) {
            return false;
        }

        return $process->isSuccessful();
    }
}
