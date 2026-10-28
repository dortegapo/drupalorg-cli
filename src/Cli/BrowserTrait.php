<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli;

use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use Symfony\Component\Console\Output\OutputInterface;

trait BrowserTrait
{
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

        $stdErr->writeln('<error>Could not open a browser automatically.</error>');
        $stdOut->writeln($displayUrl);

        return false;
    }

    protected function browserLauncher(): BrowserLauncher
    {
        return new BrowserLauncher();
    }
}
