<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Support;

use mglaman\DrupalOrgCli\BrowserTrait;
use Symfony\Component\Console\Output\OutputInterface;

final class BrowserTraitTestDouble
{
    use BrowserTrait;

    /**
     * @var list<array{string, string}>
     */
    public array $launches = [];

    /**
     * @param list<string> $availableBrowsers
     */
    public function __construct(
        private readonly array $availableBrowsers,
        private readonly bool $windowsSubsystemForLinux,
        private readonly bool $launchResult = true,
    ) {
    }

    public function defaultBrowser(): ?string
    {
        return $this->getDefaultBrowser();
    }

    public function open(string $url, OutputInterface $stdErr, OutputInterface $stdOut): void
    {
        $this->openUrl($url, $stdErr, $stdOut);
    }

    protected function isWindowsSubsystemForLinux(): bool
    {
        return $this->windowsSubsystemForLinux;
    }

    protected function browserCommandExists(string $browser): bool
    {
        return in_array($browser, $this->availableBrowsers, true);
    }

    protected function launchBrowser(string $browser, string $url): bool
    {
        $this->launches[] = [$browser, $url];
        return $this->launchResult;
    }
}
