<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

trait BrowserTrait
{

    protected function getDefaultBrowser(): ?string
    {
        $potential = $this->isWindowsSubsystemForLinux()
            ? ['wslview', 'cmd.exe', 'xdg-open', 'open']
            : (PHP_OS_FAMILY === 'Windows'
                ? ['explorer.exe']
                : (PHP_OS_FAMILY === 'Darwin' ? ['open', 'xdg-open'] : ['xdg-open', 'open']));
        foreach ($potential as $browser) {
            if ($this->browserCommandExists($browser)) {
                return $browser;
            }
        }
        return null;
    }

    protected function openUrl(
        string $url,
        OutputInterface $stdErr,
        OutputInterface $stdOut,
        ?string $displayUrl = null,
    ): bool {
        $displayUrl ??= $url;
        $browser = $this->getDefaultBrowser();
        if ($browser !== null) {
            if ($this->launchBrowser($browser, $url)) {
                $stdErr->writeln("<info>Opened</info>: $displayUrl");
                return true;
            }
        } else {
            $stdErr->writeln('<error>Could not open a browser automatically.</error>');
        }
        $stdOut->writeln($displayUrl);

        return false;
    }

    protected function isWindowsSubsystemForLinux(): bool
    {
        if (getenv('WSL_INTEROP') !== false || getenv('WSL_DISTRO_NAME') !== false) {
            return true;
        }

        $version = @file_get_contents('/proc/version');
        return is_string($version) && str_contains(strtolower($version), 'microsoft');
    }

    protected function browserCommandExists(string $browser): bool
    {
        $lookup = PHP_OS_FAMILY === 'Windows' && !$this->isWindowsSubsystemForLinux()
            ? ['where.exe', $browser]
            : ['which', $browser];
        try {
            $process = new Process($lookup);
            $process->run();
        } catch (\Exception) {
            return false;
        }

        return $process->isSuccessful();
    }

    protected function launchBrowser(string $browser, string $url): bool
    {
        $command = $browser === 'cmd.exe'
            ? [$browser, '/c', 'start', '', $url]
            : [$browser, $url];
        try {
            $process = new Process($command);
            $process->run();
        } catch (\Exception) {
            return false;
        }

        return $process->isSuccessful();
    }
}
