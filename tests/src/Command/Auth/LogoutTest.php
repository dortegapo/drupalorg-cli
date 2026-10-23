<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Auth;

use mglaman\DrupalOrg\Auth\TokenStorage;
use mglaman\DrupalOrgCli\Command\Auth\Logout;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Logout::class)]
class LogoutTest extends TestCase
{
    private string $configDirectory;

    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/drupalorg-cli-logout-' . uniqid();
        $this->configDirectory = $this->workDirectory . '/config';
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDirectory);
    }

    public function testLogoutRemovesStoredCredentials(): void
    {
        $this->storage()->write([
            'access_token' => 'access-test',
            'refresh_token' => 'refresh-test',
            'token_type' => 'Bearer',
        ]);
        $tester = new CommandTester(new Logout($this->storage()));

        $exitCode = $tester->execute(['--local' => true]);

        self::assertSame(0, $exitCode);
        self::assertSame("Local authentication credentials removed.\n", $tester->getDisplay());
        self::assertNull($this->storage()->read());
    }

    public function testLogoutWithoutCredentialsIsSuccessful(): void
    {
        $tester = new CommandTester(new Logout($this->storage()));

        $exitCode = $tester->execute(['--local' => true]);

        self::assertSame(0, $exitCode);
        self::assertSame("Local authentication credentials removed.\n", $tester->getDisplay());
        self::assertNull($this->storage()->read());
    }

    public function testLogoutRemovesCorruptCredentials(): void
    {
        mkdir($this->configDirectory, 0700, true);
        file_put_contents($this->authFile(), '{broken-json');
        $tester = new CommandTester(new Logout($this->storage()));

        $exitCode = $tester->execute(['--local' => true]);

        self::assertSame(0, $exitCode);
        self::assertFileDoesNotExist($this->authFile());
        self::assertNull($this->storage()->read());
    }

    public function testLogoutDoesNotExposeStoredCredentials(): void
    {
        $this->storage()->write([
            'access_token' => 'secret-access-test',
            'refresh_token' => 'secret-refresh-test',
        ]);
        $tester = new CommandTester(new Logout($this->storage()));

        $tester->execute(['--local' => true], ['capture_stderr_separately' => true]);

        self::assertStringNotContainsString('secret-access-test', $tester->getDisplay());
        self::assertStringNotContainsString('secret-refresh-test', $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testLogoutRequiresTheLocalOptionBeforeDeletingCredentials(): void
    {
        $this->storage()->write([
            'access_token' => 'access-test',
            'token_type' => 'Bearer',
        ]);
        $tester = new CommandTester(new Logout($this->storage()));

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertSame(
            "Drupal.org OAuth is not configured yet. Use --local to use the development Keycloak environment.\n",
            $tester->getDisplay()
        );
        self::assertSame([
            'access_token' => 'access-test',
            'token_type' => 'Bearer',
        ], $this->storage()->read());

        $localTester = new CommandTester(new Logout($this->storage()));
        $localExitCode = $localTester->execute(['--local' => true]);

        self::assertSame(0, $localExitCode);
        self::assertNull($this->storage()->read());
    }

    private function storage(): TokenStorage
    {
        return new TokenStorage($this->configDirectory);
    }

    private function authFile(): string
    {
        return $this->configDirectory . '/auth.json';
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

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
