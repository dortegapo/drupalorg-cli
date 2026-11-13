<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Browser;

use mglaman\DrupalOrg\Browser\BrowserCompanionConfigStore;
use mglaman\DrupalOrg\Browser\BrowserCompanionDistribution;
use mglaman\DrupalOrg\Browser\BrowserCompanionInstallation;
use mglaman\DrupalOrg\Browser\BrowserCompanionManager;
use mglaman\DrupalOrg\Browser\BrowserCompanionSetupStatus;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use mglaman\DrupalOrgCli\Browser\BrowserCompanionSetupInteractor;
use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(BrowserCompanionSetupInteractor::class)]
final class BrowserCompanionSetupInteractorTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    public function testCanConfigureAManuallyInstalledCompanionWithoutAStore(): void
    {
        $manager = $this->manager(new BrowserCompanionDistribution());
        $status = $this->interactor($manager)->run(
            $this->input("1\n1\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            $this->launcher(true),
        );

        self::assertSame(BrowserCompanionSetupStatus::CONFIGURED, $status);
        self::assertTrue($manager->isConfigured());
        self::assertSame(BrowserCompanionInstallation::LOCAL, $manager->getInstallation());
    }

    public function testPublishedStoreLaunchFailureHasSemanticStatus(): void
    {
        $manager = $this->manager(new BrowserCompanionDistribution([
            'firefox' => 'https://addons.example.test/companion',
        ]));
        $status = $this->interactor($manager)->run(
            $this->input("1\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            $this->launcher(false),
        );

        self::assertSame(BrowserCompanionSetupStatus::LAUNCH_FAILED, $status);
        self::assertFalse($manager->isConfigured());
    }

    public function testConfiguredCompanionDoesNotAskAgain(): void
    {
        $manager = $this->manager(new BrowserCompanionDistribution());
        $manager->configure(
            \mglaman\DrupalOrg\Browser\BrowserFamily::CHROMIUM,
            BrowserCompanionInstallation::LOCAL,
        );

        self::assertSame(
            BrowserCompanionSetupStatus::ALREADY_CONFIGURED,
            $this->interactor($manager)->run(
                $this->input(''),
                new BufferedOutput(),
                new QuestionHelper(),
                $this->launcher(true),
            ),
        );
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

    private function manager(BrowserCompanionDistribution $distribution): BrowserCompanionManager
    {
        $directory = sys_get_temp_dir() . '/drupalorg-cli-browser-' . uniqid('', true);
        mkdir($directory, 0700, true);
        $path = $directory . '/config.json';
        $this->paths[] = $path;

        return new BrowserCompanionManager(new BrowserCompanionConfigStore($path), $distribution);
    }

    private function interactor(BrowserCompanionManager $manager): BrowserCompanionSetupInteractor
    {
        return new BrowserCompanionSetupInteractor(
            $manager,
            $manager->distribution(),
            new BrowserPlatformDetector('Linux', [], ''),
        );
    }

    private function launcher(bool $result): BrowserLauncher
    {
        return new BrowserLauncher(
            new BrowserPlatformDetector('Linux', [], ''),
            static fn(array $command): bool => true,
            static function (array $command) use ($result): bool {
                return $result;
            },
        );
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
