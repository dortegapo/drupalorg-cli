<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli;

use mglaman\DrupalOrg\Browser\BrowserPlatform;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use mglaman\DrupalOrgCli\Browser\BrowserExecutableResolver;
use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

trait BrowserTrait
{

    protected function getDefaultBrowser(): ?string
    {
        $resolver = new BrowserExecutableResolver($this->traitPlatformDetector());
        foreach ($resolver->defaultCommands() as $command) {
            $browser = ($command['launch'])('')[0];
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
        if (($this->browserLauncher())->openDefaultUrl($url)) {
            $stdErr->writeln("<info>Opened</info>: $displayUrl");
            return true;
        }
        if ($this->getDefaultBrowser() === null) {
            $stdErr->writeln('<error>Could not open a browser automatically.</error>');
        }
        $stdOut->writeln($displayUrl);

        return false;
    }

    protected function browserLauncher(): BrowserLauncher
    {
        return new BrowserLauncher();
    }

    protected function browserPlatform(): BrowserPlatform
    {
        return (new BrowserPlatformDetector())->detect();
    }

    protected function traitPlatformDetector(): BrowserPlatformDetector
    {
        return match ($this->browserPlatform()) {
            BrowserPlatform::WSL => new BrowserPlatformDetector('Linux', ['WSL_INTEROP' => '1'], ''),
            BrowserPlatform::WINDOWS => new BrowserPlatformDetector('Windows', [], ''),
            BrowserPlatform::MACOS => new BrowserPlatformDetector('Darwin', [], ''),
            BrowserPlatform::LINUX => new BrowserPlatformDetector('Linux', [], ''),
        };
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
