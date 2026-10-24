<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli;

use mglaman\DrupalOrg\Browser\BrowserPlatform;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

trait BrowserTrait
{

    protected function getDefaultBrowser(): ?string
    {
        $potential = match ($this->browserPlatform()) {
            BrowserPlatform::WSL => ['wslview', 'cmd.exe', 'xdg-open', 'open'],
            BrowserPlatform::WINDOWS => ['explorer.exe'],
            BrowserPlatform::MACOS => ['open', 'xdg-open'],
            BrowserPlatform::LINUX => ['xdg-open', 'open'],
        };
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

    protected function browserPlatform(): BrowserPlatform
    {
        return (new BrowserPlatformDetector())->detect();
    }

    protected function browserCommandExists(string $browser): bool
    {
        $lookup = $this->browserPlatform() === BrowserPlatform::WINDOWS
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
