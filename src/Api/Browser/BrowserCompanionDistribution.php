<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

final class BrowserCompanionDistribution
{
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
            BrowserFamily::CHROMIUM->value => null,
            BrowserFamily::EDGE->value => null,
            BrowserFamily::FIREFOX->value => null,
            BrowserFamily::SAFARI->value => null,
        ], $storeUrls);
    }

    public function label(BrowserFamily $browserFamily): string
    {
        return match ($browserFamily) {
            BrowserFamily::CHROMIUM => 'Chrome / Chromium / compatible browser',
            BrowserFamily::EDGE => 'Microsoft Edge',
            BrowserFamily::FIREFOX => 'Firefox',
            BrowserFamily::SAFARI => 'Safari',
        };
    }

    public function storeName(BrowserFamily $browserFamily): string
    {
        return match ($browserFamily) {
            BrowserFamily::CHROMIUM => 'Chrome Web Store',
            BrowserFamily::EDGE => 'Edge Add-ons',
            BrowserFamily::FIREFOX => 'Mozilla Add-ons',
            BrowserFamily::SAFARI => 'Safari / App Store',
        };
    }

    public function storeUrl(BrowserFamily $browserFamily): ?string
    {
        return $this->storeUrls[$browserFamily->value] ?? null;
    }

    public function supportsCompanion(BrowserFamily $browserFamily): bool
    {
        return array_key_exists($browserFamily->value, $this->storeUrls);
    }

    public function hasPublishedDistribution(BrowserFamily $browserFamily): bool
    {
        return $this->storeUrl($browserFamily) !== null;
    }

    /**
     * @param iterable<BrowserFamily> $families
     *
     * @return list<BrowserFamily>
     */
    public function publishedFamilies(iterable $families): array
    {
        $published = [];
        foreach ($families as $family) {
            if ($this->hasPublishedDistribution($family)) {
                $published[] = $family;
            }
        }

        return $published;
    }
}
