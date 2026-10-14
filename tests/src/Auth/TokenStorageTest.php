<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Auth;

use mglaman\DrupalOrg\Auth\TokenStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenStorage::class)]
class TokenStorageTest extends TestCase
{
    private string $configDirectory;

    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/drupalorg-cli-token-storage-' . uniqid();
        $this->configDirectory = $this->workDirectory . '/config';
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDirectory);
    }

    public function testReadReturnsNullWhenCredentialsDoNotExist(): void
    {
        self::assertNull($this->storage()->read());
    }

    public function testWriteAndReadCompleteCredentials(): void
    {
        $credentials = [
            'access_token' => 'access-test',
            'refresh_token' => 'refresh-test',
            'expires_at' => 1790000000,
            'token_type' => 'Bearer',
            'scope' => 'project:create',
        ];

        $storage = $this->storage();
        $storage->write($credentials);

        self::assertSame($credentials, $storage->read());
        self::assertSame(
            $credentials,
            json_decode((string) file_get_contents($this->authFile()), true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertSame([], glob($this->configDirectory . '/.auth-*'));
    }

    public function testWriteAndReadOnlyAccessToken(): void
    {
        $credentials = ['access_token' => 'access-test'];

        $storage = $this->storage();
        $storage->write($credentials);

        self::assertSame($credentials, $storage->read());
    }

    public function testDeleteRemovesCredentials(): void
    {
        $storage = $this->storage();
        $storage->write(['access_token' => 'access-test']);

        $storage->delete();

        self::assertNull($storage->read());
        self::assertFileDoesNotExist($this->authFile());
    }

    public function testDeleteDoesNothingWhenCredentialsDoNotExist(): void
    {
        $this->storage()->delete();

        self::assertFileDoesNotExist($this->authFile());
    }

    public function testReadFailsForCorruptJsonWithoutChangingTheFile(): void
    {
        mkdir($this->configDirectory, 0700, true);
        file_put_contents($this->authFile(), '{broken json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not parse stored credentials.');
        try {
            $this->storage()->read();
        } finally {
            self::assertFileExists($this->authFile());
        }
    }

    public function testReadFailsWithoutAccessToken(): void
    {
        $this->writeRawCredentials(['refresh_token' => 'refresh-test']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stored credentials require a non-empty access_token.');
        $this->storage()->read();
    }

    public function testReadFailsWhenAccessTokenHasAnInvalidType(): void
    {
        $this->writeRawCredentials(['access_token' => 123]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stored credentials require a non-empty access_token.');
        $this->storage()->read();
    }

    public function testReadFailsWhenExpiresAtHasAnInvalidType(): void
    {
        $this->writeRawCredentials(['access_token' => 'access-test', 'expires_at' => 'tomorrow']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stored expires_at must be an integer.');
        $this->storage()->read();
    }

    public function testWriteFailsForInvalidCredentialsWithoutCreatingTheCredentialsFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stored credentials must be a JSON object.');
        try {
            $this->storage()->write([]);
        } finally {
            self::assertFileDoesNotExist($this->authFile());
        }
    }

    public function testReadFailsForInvalidOptionalCredentialValues(): void
    {
        $invalidCredentials = [
            ['access_token' => 'access-test', 'refresh_token' => ''],
            ['access_token' => 'access-test', 'token_type' => 123],
            ['access_token' => 'access-test', 'scope' => 123],
        ];

        foreach ($invalidCredentials as $credentials) {
            $this->writeRawCredentials($credentials);
            try {
                $this->storage()->read();
                self::fail('Expected invalid credentials to throw.');
            } catch (\RuntimeException) {
                self::assertFileExists($this->authFile());
            }
        }
    }

    public function testWritesRestrictiveFilePermissionsOnPosix(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows does not provide POSIX file permissions.');
        }

        $this->storage()->write(['access_token' => 'access-test']);
        clearstatcache(true, $this->authFile());

        self::assertSame(0, fileperms($this->authFile()) & 0077);
    }

    private function storage(): TokenStorage
    {
        return new TokenStorage($this->configDirectory);
    }

    private function authFile(): string
    {
        return $this->configDirectory . '/auth.json';
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function writeRawCredentials(array $credentials): void
    {
        if (!is_dir($this->configDirectory)) {
            mkdir($this->configDirectory, 0700, true);
        }
        file_put_contents($this->authFile(), json_encode($credentials, JSON_THROW_ON_ERROR));
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
