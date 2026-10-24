<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Browser;

use mglaman\DrupalOrg\Browser\BrowserCompanionConfigStore;
use mglaman\DrupalOrg\Browser\BrowserCompanionDistribution;
use mglaman\DrupalOrg\Browser\BrowserCompanionManager;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(BrowserCompanionConfigStore::class)]
#[CoversClass(BrowserCompanionDistribution::class)]
#[CoversClass(BrowserCompanionManager::class)]
#[CoversClass(BrowserPlatformDetector::class)]
final class BrowserCompanionTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    public function testPlatformBrowserOptions(): void
    {
        self::assertSame(
            [
                BrowserCompanionDistribution::CHROMIUM,
                BrowserCompanionDistribution::EDGE,
                BrowserCompanionDistribution::FIREFOX,
                BrowserCompanionDistribution::MANUAL,
            ],
            (new BrowserPlatformDetector('Windows', [], ''))->browserFamilies(),
        );
        self::assertSame(
            [
                BrowserCompanionDistribution::CHROMIUM,
                BrowserCompanionDistribution::EDGE,
                BrowserCompanionDistribution::FIREFOX,
                BrowserCompanionDistribution::MANUAL,
            ],
            (new BrowserPlatformDetector('Linux', ['WSL_DISTRO_NAME' => 'Ubuntu'], ''))->browserFamilies(),
        );
        self::assertSame(
            [
                BrowserCompanionDistribution::CHROMIUM,
                BrowserCompanionDistribution::FIREFOX,
                BrowserCompanionDistribution::MANUAL,
            ],
            (new BrowserPlatformDetector('Linux', [], ''))->browserFamilies(),
        );
        self::assertSame(
            [
                BrowserCompanionDistribution::CHROMIUM,
                BrowserCompanionDistribution::EDGE,
                BrowserCompanionDistribution::FIREFOX,
                BrowserCompanionDistribution::SAFARI,
                BrowserCompanionDistribution::MANUAL,
            ],
            (new BrowserPlatformDetector('Darwin', [], ''))->browserFamilies(),
        );
    }

    public function testMissingAndCorruptConfigurationAreIgnored(): void
    {
        $path = $this->tempPath();
        $store = new BrowserCompanionConfigStore($path);

        self::assertNull($store->getBrowserFamily());
        self::assertFileDoesNotExist($path);

        file_put_contents($path, '{not-json');
        self::assertNull($store->getBrowserFamily());
    }

    public function testConfigurationStoresOnlyTheBrowserFamily(): void
    {
        $path = $this->tempPath();
        $store = new BrowserCompanionConfigStore($path);
        $store->saveBrowserFamily(BrowserCompanionDistribution::FIREFOX);

        self::assertSame(BrowserCompanionDistribution::FIREFOX, $store->getBrowserFamily());
        self::assertSame(
            [
                'browser_companion' => [
                    'browser_family' => BrowserCompanionDistribution::FIREFOX,
                ],
            ],
            json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function testResetRemovesTheSavedConfiguration(): void
    {
        $path = $this->tempPath();
        $store = new BrowserCompanionConfigStore($path);
        $store->saveBrowserFamily(BrowserCompanionDistribution::FIREFOX);

        $store->reset();

        self::assertNull($store->getBrowserFamily());
        self::assertFileDoesNotExist($path);
    }

    public function testMissingStoreListingFallsBackWithoutSaving(): void
    {
        $path = $this->tempPath();
        $manager = new BrowserCompanionManager(
            new BrowserCompanionConfigStore($path),
            new BrowserCompanionDistribution(),
            new BrowserPlatformDetector('Linux', [], ''),
        );
        $output = new BufferedOutput();

        self::assertFalse($manager->setup(
            $this->input("1\n"),
            $output,
            new QuestionHelper(),
            static function (string $url): void {
            },
        ));
        self::assertFileDoesNotExist($path);
        self::assertStringContainsString('manual Drupal.org project creation', $output->fetch());
    }

    public function testSetupPersistsAfterInstallationConfirmation(): void
    {
        $path = $this->tempPath();
        $manager = new BrowserCompanionManager(
            new BrowserCompanionConfigStore($path),
            new BrowserCompanionDistribution([
                BrowserCompanionDistribution::CHROMIUM => 'https://store.example.test/companion',
            ]),
            new BrowserPlatformDetector('Linux', [], ''),
        );
        $opened = [];

        self::assertTrue($manager->setup(
            $this->input("1\n\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            static function (string $url) use (&$opened): void {
                $opened[] = $url;
            },
        ));
        self::assertSame(['https://store.example.test/companion'], $opened);
        self::assertSame(BrowserCompanionDistribution::CHROMIUM, $manager->getBrowserFamily());
        self::assertTrue($manager->supportsAutomaticPrefill());
    }

    public function testManualSelectionDoesNotFailOrPersist(): void
    {
        $path = $this->tempPath();
        $manager = new BrowserCompanionManager(
            new BrowserCompanionConfigStore($path),
            new BrowserCompanionDistribution(),
            new BrowserPlatformDetector('Linux', [], ''),
        );
        $output = new BufferedOutput();

        self::assertFalse($manager->setup(
            $this->input("3\n"),
            $output,
            new QuestionHelper(),
            static function (string $url): void {
            },
        ));
        self::assertFileDoesNotExist($path);
        self::assertStringContainsString('manual Drupal.org project creation', $output->fetch());
    }

    public function testBrowserLaunchFailureFallsBackWithoutSaving(): void
    {
        $path = $this->tempPath();
        $manager = new BrowserCompanionManager(
            new BrowserCompanionConfigStore($path),
            new BrowserCompanionDistribution([
                BrowserCompanionDistribution::CHROMIUM => 'https://store.example.test/companion',
            ]),
            new BrowserPlatformDetector('Linux', [], ''),
        );
        $output = new BufferedOutput();

        self::assertFalse($manager->setup(
            $this->input("1\n"),
            $output,
            new QuestionHelper(),
            static function (string $url): bool {
                return false;
            },
        ));
        self::assertFileDoesNotExist($path);
        self::assertStringContainsString('manual Drupal.org project creation', $output->fetch());
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

    private function input(string $contents): ArrayInput
    {
        $input = new ArrayInput([]);
        $input->setInteractive(true);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);
        $input->setStream($stream);

        return $input;
    }
}
