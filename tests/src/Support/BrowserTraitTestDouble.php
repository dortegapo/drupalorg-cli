<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Support;

use mglaman\DrupalOrgCli\BrowserTrait;
use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
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

    public function open(
        string $url,
        OutputInterface $stdErr,
        OutputInterface $stdOut,
        ?string $displayUrl = null,
    ): bool {
        return $this->openUrl($url, $stdErr, $stdOut, $displayUrl);
    }

    protected function browserLauncher(): BrowserLauncher
    {
        return new BrowserLauncher(
            new BrowserPlatformDetector('Linux', $this->windowsSubsystemForLinux ? ['WSL_INTEROP' => '1'] : [], ''),
            function (array $command): bool {
                $browser = $command[0] === 'cmd.exe'
                    ? 'cmd.exe'
                    : ($command[1] ?? $command[0] ?? '');

                return in_array($browser, $this->availableBrowsers, true);
            },
            function (array $command): bool {
                $target = (string) end($command);
                if ($command[0] === 'cmd.exe' && preg_match('/"([^"]+)"$/', $target, $matches) === 1) {
                    $target = $matches[1];
                }
                $this->launches[] = [$command[0], $target];

                return $this->launchResult;
            },
        );
    }
}
