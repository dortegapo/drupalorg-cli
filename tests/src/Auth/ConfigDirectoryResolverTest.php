<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Auth;

use mglaman\DrupalOrg\Auth\ConfigDirectoryResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigDirectoryResolver::class)]
class ConfigDirectoryResolverTest extends TestCase
{
    public function testUsesXdgConfigHomeOnLinux(): void
    {
        $resolver = new ConfigDirectoryResolver([
            'XDG_CONFIG_HOME' => '/custom/config',
            'HOME' => '/home/test',
        ], 'Linux');

        self::assertSame('/custom/config/drupalorg-cli', $resolver->resolve());
    }

    public function testUsesSingleLeadingSlashForXdgConfigHomeAtThePosixRoot(): void
    {
        $resolver = new ConfigDirectoryResolver(['XDG_CONFIG_HOME' => '/'], 'Linux');

        self::assertSame('/drupalorg-cli', $resolver->resolve());
    }

    public function testFallsBackToHomeOnLinux(): void
    {
        $resolver = new ConfigDirectoryResolver(['HOME' => '/home/test'], 'Linux');

        self::assertSame('/home/test/.config/drupalorg-cli', $resolver->resolve());
    }

    public function testUsesSingleLeadingSlashForHomeAtThePosixRoot(): void
    {
        $resolver = new ConfigDirectoryResolver(['HOME' => '/'], 'Linux');

        self::assertSame('/.config/drupalorg-cli', $resolver->resolve());
    }

    public function testXdgConfigHomeHasPriorityOverOtherLocations(): void
    {
        $resolver = new ConfigDirectoryResolver([
            'XDG_CONFIG_HOME' => '/xdg',
            'HOME' => '/home/test',
            'APPDATA' => 'C:\\Users\\Test\\AppData\\Roaming',
        ], 'Linux');

        self::assertSame('/xdg/drupalorg-cli', $resolver->resolve());
    }

    public function testLinuxIgnoresAppData(): void
    {
        $resolver = new ConfigDirectoryResolver([
            'HOME' => '/home/dev',
            'APPDATA' => 'C:\\Users\\dev\\AppData\\Roaming',
        ], 'Linux');

        self::assertSame('/home/dev/.config/drupalorg-cli', $resolver->resolve());
    }

    public function testWindowsUsesAppData(): void
    {
        $resolver = new ConfigDirectoryResolver([
            'APPDATA' => 'C:\\Users\\Test\\AppData\\Roaming',
            'HOME' => 'C:\\Users\\Test',
        ], 'Windows');

        self::assertSame('C:\\Users\\Test\\AppData\\Roaming\\drupalorg-cli', $resolver->resolve());
    }

    public function testWindowsAppDataAtTheRootProducesAValidPath(): void
    {
        $resolver = new ConfigDirectoryResolver(['APPDATA' => 'C:\\'], 'Windows');

        self::assertSame('C:\\drupalorg-cli', $resolver->resolve());
    }

    public function testWindowsGivesXdgConfigHomePriority(): void
    {
        $resolver = new ConfigDirectoryResolver([
            'XDG_CONFIG_HOME' => 'C:\\custom',
            'APPDATA' => 'C:\\Users\\Test\\AppData\\Roaming',
        ], 'Windows');

        self::assertSame('C:\\custom\\drupalorg-cli', $resolver->resolve());
    }

    public function testWindowsFallsBackToHome(): void
    {
        $resolver = new ConfigDirectoryResolver(['HOME' => 'C:\\Users\\Test'], 'Windows');

        self::assertSame('C:\\Users\\Test\\.config\\drupalorg-cli', $resolver->resolve());
    }

    public function testIgnoresEmptyEnvironmentValues(): void
    {
        $resolver = new ConfigDirectoryResolver([
            'XDG_CONFIG_HOME' => '   ',
            'HOME' => '/home/test',
        ], 'Linux');

        self::assertSame('/home/test/.config/drupalorg-cli', $resolver->resolve());
    }

    public function testFailsWhenNoConfigurationDirectoryCanBeDetermined(): void
    {
        $resolver = new ConfigDirectoryResolver([], 'Linux');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not determine the configuration directory.');
        $resolver->resolve();
    }

    public function testDoesNotCreateTheResolvedDirectory(): void
    {
        $configHome = sys_get_temp_dir() . '/drupalorg-cli-config-directory-' . uniqid();
        $resolver = new ConfigDirectoryResolver(['XDG_CONFIG_HOME' => $configHome], 'Linux');

        self::assertDirectoryDoesNotExist($configHome);
        self::assertSame($configHome . '/drupalorg-cli', $resolver->resolve());
        self::assertDirectoryDoesNotExist($configHome);
    }
}
