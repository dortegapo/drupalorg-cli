<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\DrupalRuntimeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

#[CoversClass(DrupalRuntimeResolver::class)]
class DrupalRuntimeResolverTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/drupalorg-cli-runtime-resolver-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDir);
    }

    public function testNullProjectRootReturnsNull(): void
    {
        $finder = $this->createMock(ExecutableFinder::class);
        $finder->expects(self::never())->method('find');

        self::assertNull((new DrupalRuntimeResolver($finder))->resolve(null));
    }

    public function testDdevTakesPriorityOverLocalDrush(): void
    {
        $this->createDdevConfig();
        $this->createLocalDrush();

        $resolver = new DrupalRuntimeResolver($this->createExecutableFinder([
            'ddev' => '/usr/local/bin/ddev',
        ]));

        self::assertSame(
            [
                'type' => 'ddev',
                'workingDirectory' => $this->workDir,
                'command' => ['/usr/local/bin/ddev', 'drush'],
            ],
            $resolver->resolve($this->workDir)
        );
    }

    public function testLandoTakesPriorityOverLocalDrush(): void
    {
        $this->createLandoConfig();
        $this->createLocalDrush();

        $resolver = new DrupalRuntimeResolver($this->createExecutableFinder([
            'lando' => '/usr/local/bin/lando',
        ]));

        self::assertSame(
            [
                'type' => 'lando',
                'workingDirectory' => $this->workDir,
                'command' => ['/usr/local/bin/lando', 'drush'],
            ],
            $resolver->resolve($this->workDir)
        );
    }

    public function testDdevTakesPriorityWhenBothRuntimesAreDeclared(): void
    {
        $this->createDdevConfig();
        $this->createLandoConfig();

        $resolver = new DrupalRuntimeResolver($this->createExecutableFinder([
            'ddev' => '/usr/local/bin/ddev',
            'lando' => '/usr/local/bin/lando',
        ]));

        self::assertSame('ddev', $resolver->resolve($this->workDir)['type']);
    }

    public function testLandoIsUsedWhenDdevLauncherIsUnavailable(): void
    {
        $this->createDdevConfig();
        $this->createLandoConfig();

        $resolver = new DrupalRuntimeResolver($this->createExecutableFinder([
            'lando' => '/usr/local/bin/lando',
        ]));

        self::assertSame('lando', $resolver->resolve($this->workDir)['type']);
    }

    public function testLocalDrushIsUsedWhenDeclaredLauncherIsUnavailable(): void
    {
        $this->createDdevConfig();
        $this->createLocalDrush();

        $resolver = new DrupalRuntimeResolver($this->createExecutableFinder());

        self::assertSame('local-drush', $resolver->resolve($this->workDir)['type']);
    }

    public function testLocalDrushIsUsedWhenItIsTheOnlyRuntime(): void
    {
        $this->createLocalDrush();

        $resolver = new DrupalRuntimeResolver($this->createExecutableFinder());

        self::assertSame(
            [
                'type' => 'local-drush',
                'workingDirectory' => $this->workDir,
                'command' => [$this->workDir . '/vendor/bin/drush'],
            ],
            $resolver->resolve($this->workDir)
        );
    }

    public function testNonExecutableLocalDrushIsIgnored(): void
    {
        $drush = $this->createLocalDrush();
        chmod($drush, 0644);

        $resolver = new DrupalRuntimeResolver($this->createExecutableFinder());

        self::assertNull($resolver->resolve($this->workDir));
    }

    public function testMissingRuntimeReturnsNull(): void
    {
        $resolver = new DrupalRuntimeResolver($this->createExecutableFinder());

        self::assertNull($resolver->resolve($this->workDir));
    }

    /**
     * @param array<string, string> $executables
     */
    private function createExecutableFinder(array $executables = []): ExecutableFinder
    {
        $finder = $this->createMock(ExecutableFinder::class);
        $finder->method('find')->willReturnCallback(
            static fn (string $name): ?string => $executables[$name] ?? null
        );

        return $finder;
    }

    private function createDdevConfig(): void
    {
        mkdir($this->workDir . '/.ddev');
        file_put_contents($this->workDir . '/.ddev/config.yaml', 'name: test');
    }

    private function createLandoConfig(): void
    {
        file_put_contents($this->workDir . '/.lando.yml', "name: test\n");
    }

    private function createLocalDrush(): string
    {
        $directory = $this->workDir . '/vendor/bin';
        mkdir($directory, 0755, true);
        $drush = $directory . '/drush';
        file_put_contents($drush, "#!/bin/sh\n");
        chmod($drush, 0755);

        return $drush;
    }

    private static function removeDirectory(string $directory): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
