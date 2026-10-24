<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

final class BrowserCompanionManager
{
    public function __construct(
        private readonly BrowserCompanionConfigStore $configStore = new BrowserCompanionConfigStore(),
        private readonly BrowserCompanionDistribution $distribution = new BrowserCompanionDistribution(),
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->getBrowserFamily() !== null && $this->getInstallation() !== null;
    }

    public function getBrowserFamily(): ?BrowserFamily
    {
        return $this->configStore->getBrowserFamily();
    }

    public function getInstallation(): ?BrowserCompanionInstallation
    {
        return $this->configStore->getInstallation();
    }

    public function supportsCompanion(BrowserFamily $browserFamily): bool
    {
        return $this->distribution->supportsCompanion($browserFamily);
    }

    public function hasPublishedDistribution(BrowserFamily $browserFamily): bool
    {
        return $this->distribution->hasPublishedDistribution($browserFamily);
    }

    public function configure(
        BrowserFamily $browserFamily,
        BrowserCompanionInstallation $installation,
    ): void {
        if (!$this->supportsCompanion($browserFamily)) {
            throw new \InvalidArgumentException('The selected browser does not support the companion.');
        }

        $this->configStore->save($browserFamily, $installation);
    }

    public function reset(): void
    {
        $this->configStore->reset();
    }

    public function storeUrl(BrowserFamily $browserFamily): ?string
    {
        return $this->distribution->storeUrl($browserFamily);
    }

    public function storeName(BrowserFamily $browserFamily): string
    {
        return $this->distribution->storeName($browserFamily);
    }

    /**
     * @param iterable<BrowserFamily> $families
     *
     * @return list<BrowserFamily>
     */
    public function publishedFamilies(iterable $families): array
    {
        return $this->distribution->publishedFamilies($families);
    }

    public function distribution(): BrowserCompanionDistribution
    {
        return $this->distribution;
    }
}
