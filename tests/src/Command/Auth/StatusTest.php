<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Auth;

use mglaman\DrupalOrg\Auth\TokenStorage;
use mglaman\DrupalOrgCli\Command\Auth\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Status::class)]
class StatusTest extends TestCase
{
    private string $configDirectory;

    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/drupalorg-cli-status-' . uniqid();
        $this->configDirectory = $this->workDirectory . '/config';
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDirectory);
    }

    public function testReportsWhenNoCredentialsAreStored(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertSame("Not authenticated.\n", $tester->getDisplay());
    }

    public function testReportsCredentialsWithUnknownExpiration(): void
    {
        $this->storage()->write([
            'access_token' => 'access-test',
            'token_type' => 'Bearer',
        ]);
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertSame(
            "Authenticated with stored credentials (expiration unknown).\n",
            $tester->getDisplay()
        );
    }

    public function testReportsCredentialsWithAFutureExpiration(): void
    {
        $this->storage()->write([
            'access_token' => 'access-test',
            'token_type' => 'Bearer',
            'expires_at' => 1790003600,
        ]);
        $tester = new CommandTester($this->command(1790000000));

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertSame("Authenticated with stored credentials.\n", $tester->getDisplay());
    }

    public function testReportsCredentialsExpiredBeforeTheCurrentTime(): void
    {
        $this->storage()->write([
            'access_token' => 'access-test',
            'expires_at' => 1789999999,
        ]);
        $tester = new CommandTester($this->command(1790000000));

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertSame("Stored credentials are expired.\n", $tester->getDisplay());
    }

    public function testReportsCredentialsExpiredAtTheCurrentTime(): void
    {
        $this->storage()->write([
            'access_token' => 'access-test',
            'expires_at' => 1790000000,
        ]);
        $tester = new CommandTester($this->command(1790000000));

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertSame("Stored credentials are expired.\n", $tester->getDisplay());
    }

    public function testDoesNotExposeStoredCredentialValues(): void
    {
        $this->storage()->write([
            'access_token' => 'secret-access-test',
            'refresh_token' => 'secret-refresh-test',
            'expires_at' => 1790003600,
            'token_type' => 'Bearer',
            'scope' => 'project:create',
        ]);
        $tester = new CommandTester($this->command(1790000000));

        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertStringNotContainsString('secret-access-test', $tester->getDisplay());
        self::assertStringNotContainsString('secret-refresh-test', $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
    }

    private function command(?int $time = null): Status
    {
        return new Status(
            $this->storage(),
            $time === null ? null : static fn (): int => $time,
        );
    }

    private function storage(): TokenStorage
    {
        return new TokenStorage($this->configDirectory);
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
