<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

final class BrowserCompanionDistribution
{
    public const CHROMIUM = 'chromium';
    public const EDGE = 'edge';
    public const FIREFOX = 'firefox';
    public const SAFARI = 'safari';
    public const MANUAL = 'manual';

    /**
     * @var array<string, string|null>
     */
    private readonly array $storeUrls;

    /**
     * @param array<string, string|null> $storeUrls
     */
    public function __construct(array $storeUrls = [])
    {
        $this->storeUrls = array_replace([
            self::CHROMIUM => null,
            self::EDGE => null,
            self::FIREFOX => null,
            self::SAFARI => null,
            self::MANUAL => null,
        ], $storeUrls);
    }

    public function label(string $browserFamily): string
    {
        return match ($browserFamily) {
            self::CHROMIUM => 'Chrome / Chromium / compatible browser',
            self::EDGE => 'Microsoft Edge',
            self::FIREFOX => 'Firefox',
            self::SAFARI => 'Safari',
            self::MANUAL => 'Other browser / manual filling',
            default => $browserFamily,
        };
    }

    public function storeName(string $browserFamily): ?string
    {
        return match ($browserFamily) {
            self::CHROMIUM => 'Chrome Web Store',
            self::EDGE => 'Edge Add-ons',
            self::FIREFOX => 'Mozilla Add-ons',
            self::SAFARI => 'Safari / App Store',
            default => null,
        };
    }

    public function storeUrl(string $browserFamily): ?string
    {
        return $this->storeUrls[$browserFamily] ?? null;
    }

    public function supportsAutomaticPrefill(string $browserFamily): bool
    {
        return $browserFamily !== self::MANUAL && $this->storeUrl($browserFamily) !== null;
    }

    public function isKnown(string $browserFamily): bool
    {
        return array_key_exists($browserFamily, $this->storeUrls);
    }
}
