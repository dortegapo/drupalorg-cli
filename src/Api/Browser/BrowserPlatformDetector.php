<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

final class BrowserPlatformDetector
{
    /**
     * @param array<string, string|false> $environment
     */
    public function __construct(
        private readonly ?string $osFamily = null,
        private readonly ?array $environment = null,
        private readonly ?string $procVersion = null,
    ) {
    }

    public function detect(): BrowserPlatform
    {
        $osFamily = $this->osFamily ?? PHP_OS_FAMILY;
        $environment = $this->environment ?? $_ENV + $_SERVER;
        $procVersion = $this->procVersion;

        if ($procVersion === null && in_array(strtolower($osFamily), ['linux', 'unix'], true)) {
            $procVersion = @file_get_contents('/proc/version');
        }

        if (array_key_exists('WSL_INTEROP', $environment)
            || array_key_exists('WSL_DISTRO_NAME', $environment)
            || (is_string($procVersion) && str_contains(strtolower($procVersion), 'microsoft'))
        ) {
            return BrowserPlatform::WSL;
        }

        return match (strtolower($osFamily)) {
            'windows' => BrowserPlatform::WINDOWS,
            'darwin' => BrowserPlatform::MACOS,
            default => BrowserPlatform::LINUX,
        };
    }

    /**
     * @return list<BrowserFamily>
     */
    public function browserFamilies(): array
    {
        return match ($this->detect()) {
            BrowserPlatform::WINDOWS, BrowserPlatform::WSL => [
                BrowserFamily::CHROMIUM,
                BrowserFamily::EDGE,
                BrowserFamily::FIREFOX,
            ],
            BrowserPlatform::MACOS => [
                BrowserFamily::CHROMIUM,
                BrowserFamily::EDGE,
                BrowserFamily::FIREFOX,
                BrowserFamily::SAFARI,
            ],
            BrowserPlatform::LINUX => [
                BrowserFamily::CHROMIUM,
                BrowserFamily::FIREFOX,
            ],
        };
    }
}
