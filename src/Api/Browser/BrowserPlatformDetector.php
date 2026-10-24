<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

final class BrowserPlatformDetector
{
    public const WINDOWS = 'windows';
    public const WSL = 'wsl';
    public const LINUX = 'linux';
    public const MACOS = 'macos';

    /**
     * @param array<string, string|false> $environment
     */
    public function __construct(
        private readonly ?string $osFamily = null,
        private readonly ?array $environment = null,
        private readonly ?string $procVersion = null,
    ) {
    }

    public function detect(): string
    {
        $osFamily = $this->osFamily ?? PHP_OS_FAMILY;
        $environment = $this->environment ?? $_ENV + $_SERVER;
        $procVersion = $this->procVersion;

        if ($procVersion === null && in_array(strtolower($osFamily), ['linux', 'unix'], true)) {
            $procVersion = @file_get_contents('/proc/version');
        }

        if (isset($environment['WSL_INTEROP']) || isset($environment['WSL_DISTRO_NAME'])
            || (is_string($procVersion) && str_contains(strtolower($procVersion), 'microsoft'))) {
            return self::WSL;
        }

        return match (strtolower($osFamily)) {
            'windows' => self::WINDOWS,
            'darwin' => self::MACOS,
            default => self::LINUX,
        };
    }

    /**
     * @return list<string>
     */
    public function browserFamilies(): array
    {
        return match ($this->detect()) {
            self::WINDOWS, self::WSL => [
                BrowserCompanionDistribution::CHROMIUM,
                BrowserCompanionDistribution::EDGE,
                BrowserCompanionDistribution::FIREFOX,
                BrowserCompanionDistribution::MANUAL,
            ],
            self::MACOS => [
                BrowserCompanionDistribution::CHROMIUM,
                BrowserCompanionDistribution::EDGE,
                BrowserCompanionDistribution::FIREFOX,
                BrowserCompanionDistribution::SAFARI,
                BrowserCompanionDistribution::MANUAL,
            ],
            default => [
                BrowserCompanionDistribution::CHROMIUM,
                BrowserCompanionDistribution::FIREFOX,
                BrowserCompanionDistribution::MANUAL,
            ],
        };
    }
}
