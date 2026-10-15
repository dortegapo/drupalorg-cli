<?php

namespace mglaman\DrupalOrgCli;

use Symfony\Component\Console\Output\OutputInterface;

trait BrowserTrait
{

    protected function getDefaultBrowser(): ?string
    {
        $potential = $this->isWindowsSubsystemForLinux()
            ? ['wslview', 'cmd.exe', 'xdg-open', 'open', 'start']
            : ['xdg-open', 'open', 'start'];
        foreach ($potential as $browser) {
            if ($this->browserCommandExists($browser)) {
                return $browser;
            }
        }
        return null;
    }

    protected function openUrl(string $url, OutputInterface $stdErr, OutputInterface $stdOut): void
    {
        $browser = $this->getDefaultBrowser();
        if ($browser !== null) {
            if ($this->launchBrowser($browser, $url)) {
                $stdErr->writeln("<info>Opened</info>: $url");
                return;
            }
        } else {
            $stdErr->writeln("<error>Browser not found: $browser</error>");
        }
        $stdOut->writeln($url);
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
        $output = shell_exec(sprintf('command -v %s', escapeshellarg($browser)));
        return is_string($output) && trim($output) !== '';
    }

    protected function launchBrowser(string $browser, string $url): bool
    {
        $command = $browser === 'cmd.exe'
            ? sprintf('%s /c start "" %s', escapeshellcmd($browser), escapeshellarg($url))
            : sprintf('%s %s', escapeshellcmd($browser), escapeshellarg($url));
        $output = [];
        $exitCode = 1;
        exec($command . ' 2>&1', $output, $exitCode);

        return $exitCode === 0;
    }
}
