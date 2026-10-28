<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Browser;

use mglaman\DrupalOrg\Browser\BrowserCompanionConfigStore;
use mglaman\DrupalOrg\Browser\BrowserCompanionDistribution;
use mglaman\DrupalOrg\Browser\BrowserCompanionInstallation;
use mglaman\DrupalOrg\Browser\BrowserCompanionManager;
use mglaman\DrupalOrg\Browser\BrowserFamily;
use mglaman\DrupalOrg\Browser\BrowserPlatform;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrowserCompanionConfigStore::class)]
#[CoversClass(BrowserCompanionDistribution::class)]
#[CoversClass(BrowserCompanionManager::class)]
#[CoversClass(BrowserPlatformDetector::class)]
final class BrowserCompanionTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    public function testPlatformDetectionHasOneTypedSourceOfTruth(): void
    {
        self::assertSame(BrowserPlatform::WINDOWS, (new BrowserPlatformDetector('Windows', [], ''))->detect());
        self::assertSame(BrowserPlatform::LINUX, (new BrowserPlatformDetector('Linux', [], ''))->detect());
        self::assertSame(BrowserPlatform::MACOS, (new BrowserPlatformDetector('Darwin', [], ''))->detect());
        self::assertSame(
            BrowserPlatform::WSL,
            (new BrowserPlatformDetector('Linux', ['WSL_INTEROP' => '1'], ''))->detect(),
        );
        self::assertSame(
            BrowserPlatform::WSL,
            (new BrowserPlatformDetector('Linux', ['WSL_DISTRO_NAME' => 'Ubuntu'], ''))->detect(),
        );
        self::assertSame(
            BrowserPlatform::WSL,
            (new BrowserPlatformDetector('Linux', [], 'Linux version Microsoft'))->detect(),
        );
    }

    public function testPlatformBrowserOptionsContainOnlyRealBrowsers(): void
    {
        self::assertSame(
            [BrowserFamily::CHROMIUM, BrowserFamily::EDGE, BrowserFamily::FIREFOX],
            (new BrowserPlatformDetector('Windows', [], ''))->browserFamilies(),
        );
        self::assertSame(
            [BrowserFamily::CHROMIUM, BrowserFamily::FIREFOX],
            (new BrowserPlatformDetector('Linux', [], ''))->browserFamilies(),
        );
        self::assertSame(
            [BrowserFamily::CHROMIUM, BrowserFamily::EDGE, BrowserFamily::FIREFOX, BrowserFamily::SAFARI],
            (new BrowserPlatformDetector('Darwin', [], ''))->browserFamilies(),
        );
    }

    public function testDistributionSeparatesSupportFromPublishedListings(): void
    {
        $distribution = new BrowserCompanionDistribution();

        self::assertTrue($distribution->supportsCompanion(BrowserFamily::FIREFOX));
        self::assertFalse($distribution->hasPublishedDistribution(BrowserFamily::FIREFOX));
        self::assertNull($distribution->storeUrl(BrowserFamily::FIREFOX));

        $published = new BrowserCompanionDistribution([
            BrowserFamily::FIREFOX->value => 'https://addons.example.test/companion',
        ]);
        self::assertTrue($published->hasPublishedDistribution(BrowserFamily::FIREFOX));
    }

    public function testMissingAndCorruptOrUnknownConfigurationAreIgnored(): void
    {
        $path = $this->tempPath();
        $store = new BrowserCompanionConfigStore($path);

        self::assertNull($store->getBrowserFamily());
        self::assertNull($store->getInstallation());
        file_put_contents($path, '{not-json');
        self::assertNull($store->getBrowserFamily());

        file_put_contents($path, json_encode([
            'version' => 1,
            'browser_companion' => ['browser_family' => 'unknown'],
        ], JSON_THROW_ON_ERROR));
        self::assertNull($store->getBrowserFamily());

        file_put_contents($path, json_encode([
            'version' => 2,
            'browser_companion' => ['browser_family' => BrowserFamily::FIREFOX->value],
        ], JSON_THROW_ON_ERROR));
        self::assertNull($store->getBrowserFamily());
    }

    public function testConfigurationIsVersionedAtomicAndPermissioned(): void
    {
        $path = $this->tempPath();
        $store = new BrowserCompanionConfigStore($path);
        $store->save(BrowserFamily::FIREFOX, BrowserCompanionInstallation::LOCAL);

        self::assertSame(BrowserFamily::FIREFOX, $store->getBrowserFamily());
        self::assertSame(BrowserCompanionInstallation::LOCAL, $store->getInstallation());
        self::assertSame([
            'version' => 1,
            'browser_companion' => [
                'browser_family' => 'firefox',
                'installation' => 'local',
            ],
        ], json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame(0700, fileperms(dirname($path)) & 0777);
        }
    }

    public function testLegacyConfigurationDefaultsToStoreInstallation(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, json_encode([
            'browser_companion' => ['browser_family' => BrowserFamily::FIREFOX->value],
        ], JSON_THROW_ON_ERROR));

        $store = new BrowserCompanionConfigStore($path);
        self::assertSame(BrowserFamily::FIREFOX, $store->getBrowserFamily());
        self::assertSame(BrowserCompanionInstallation::STORE, $store->getInstallation());
    }

    public function testManagerCanConfigureLocalCompanionWithoutStoreListing(): void
    {
        $path = $this->tempPath();
        $manager = new BrowserCompanionManager(new BrowserCompanionConfigStore($path));

        self::assertFalse($manager->isConfigured());
        $manager->configure(BrowserFamily::CHROMIUM, BrowserCompanionInstallation::LOCAL);

        self::assertTrue($manager->isConfigured());
        self::assertSame(BrowserFamily::CHROMIUM, $manager->getBrowserFamily());
        self::assertSame(BrowserCompanionInstallation::LOCAL, $manager->getInstallation());
        self::assertTrue($manager->supportsCompanion(BrowserFamily::CHROMIUM));
        self::assertFalse($manager->hasPublishedDistribution(BrowserFamily::CHROMIUM));
    }

    public function testResetRemovesTheSavedConfiguration(): void
    {
        $path = $this->tempPath();
        $store = new BrowserCompanionConfigStore($path);
        $store->save(BrowserFamily::FIREFOX, BrowserCompanionInstallation::STORE);

        $store->reset();

        self::assertNull($store->getBrowserFamily());
        self::assertFileDoesNotExist($path);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
            $directory = dirname($path);
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function tempPath(): string
    {
        $directory = sys_get_temp_dir() . '/drupalorg-cli-browser-' . uniqid('', true);
        mkdir($directory, 0700, true);
        $path = $directory . '/config.json';
        $this->paths[] = $path;

        return $path;
    }
}
