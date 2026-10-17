<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Auth;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Auth\AuthenticationRequiredException;
use mglaman\DrupalOrg\Auth\Authenticator;
use mglaman\DrupalOrg\Auth\DeviceAuthorizationClient;
use mglaman\DrupalOrg\Auth\OAuthConfiguration;
use mglaman\DrupalOrg\Auth\TokenStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Authenticator::class)]
class AuthenticatorTest extends TestCase
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $history = [];

    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/drupalorg-cli-authenticator-' . uniqid();
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDirectory);
    }

    public function testUsableStoredCredentialsDoNotMakeAnHttpRequest(): void
    {
        $storage = $this->storage();
        $storage->write([
            'access_token' => 'stored-access',
            'refresh_token' => 'stored-refresh',
            'expires_at' => 1061,
            'token_type' => 'Bearer',
        ]);

        $result = $this->authenticator([], 1000, $storage)->authenticate(false);

        self::assertSame('stored-access', $result->accessToken);
        self::assertSame('stored', $result->method);
        self::assertSame([], $this->history);
    }

    public function testCredentialsWithoutExpirationAreUsable(): void
    {
        $storage = $this->storage();
        $storage->write(['access_token' => 'stored-access']);

        $result = $this->authenticator([], 1000, $storage)->authenticate(false);

        self::assertSame('stored', $result->method);
        self::assertSame([], $this->history);
    }

    public function testCredentialsAtTheExpiryLeewayBoundaryAreRefreshed(): void
    {
        $storage = $this->storage();
        $storage->write([
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => 1060,
        ]);

        $result = $this->authenticator([
            self::json(['access_token' => 'new-access', 'token_type' => 'Bearer']),
        ], 1000, $storage)->authenticate(false);

        self::assertSame('refreshed', $result->method);
        self::assertSame('new-access', $result->accessToken);
    }

    public function testRefreshSuccessStoresNewCredentialsAndPreservesRefreshToken(): void
    {
        $storage = $this->storage();
        $storage->write([
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => 1060,
            'token_type' => 'Bearer',
            'scope' => 'profile',
        ]);

        $result = $this->authenticator([
            self::json([
                'access_token' => 'new-access',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
        ], 1000, $storage)->authenticate(false);

        self::assertSame('new-access', $result->accessToken);
        self::assertSame('refreshed', $result->method);
        self::assertSame([
            'access_token' => 'new-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => 4600,
            'token_type' => 'Bearer',
            'scope' => 'profile',
        ], $storage->read());
        self::assertCount(1, $this->history);
    }

    public function testRefreshSuccessReplacesRotatedRefreshToken(): void
    {
        $storage = $this->storage();
        $storage->write([
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => 1000,
        ]);

        $this->authenticator([
            self::json([
                'access_token' => 'new-access',
                'token_type' => 'Bearer',
                'refresh_token' => 'new-refresh',
            ]),
        ], 1000, $storage)->authenticate(false);

        self::assertSame('new-refresh', $storage->read()['refresh_token']);
    }

    public function testInvalidGrantFallsBackToDeviceFlowInteractively(): void
    {
        $storage = $this->storage();
        $storage->write([
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => 1000,
        ]);
        $authorization = $this->deviceAuthorization();
        $presented = null;

        $result = $this->authenticator([
            self::json(['error' => 'invalid_grant'], 400),
            self::json($authorization),
            self::json(['access_token' => 'device-access', 'token_type' => 'Bearer']),
        ], 1000, $storage)->authenticate(
            true,
            false,
            static function (array $value) use (&$presented): void {
                $presented = $value;
            },
        );

        self::assertSame('device', $result->method);
        self::assertSame('device-access', $result->accessToken);
        self::assertIsArray($presented);
        self::assertArrayNotHasKey('device_code', $presented);
        self::assertSame(['access_token' => 'device-access', 'token_type' => 'Bearer'], $storage->read());
    }

    public function testInvalidGrantFailsWithoutStartingDeviceFlowNonInteractively(): void
    {
        $storage = $this->storage();
        $storage->write([
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => 1000,
        ]);

        $this->expectException(AuthenticationRequiredException::class);
        try {
            $this->authenticator([
                self::json(['error' => 'invalid_grant'], 400),
            ], 1000, $storage)->authenticate(false);
        } finally {
            self::assertCount(1, $this->history);
            self::assertSame('old-access', $storage->read()['access_token']);
        }
    }

    public function testRefreshServerFailureDoesNotStartDeviceFlow(): void
    {
        $storage = $this->storage();
        $storage->write([
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => 1000,
        ]);

        $this->expectExceptionMessage('Refresh token request failed with HTTP 500.');
        try {
            $this->authenticator([new Response(500)], 1000, $storage)->authenticate(true);
        } finally {
            self::assertCount(1, $this->history);
            self::assertSame('old-access', $storage->read()['access_token']);
        }
    }

    public function testMalformedRefreshResponseDoesNotStartDeviceFlow(): void
    {
        $storage = $this->storage();
        $storage->write([
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => 1000,
        ]);

        $this->expectExceptionMessage('Could not parse refresh token response.');
        try {
            $this->authenticator([
                new Response(200, ['Content-Type' => 'application/json'], 'not-json'),
            ], 1000, $storage)->authenticate(true);
        } finally {
            self::assertCount(1, $this->history);
            self::assertSame('old-access', $storage->read()['access_token']);
        }
    }

    public function testDeviceAuthorizationStoresCredentialsOnlyAfterSuccess(): void
    {
        $storage = $this->storage();
        $storage->write(['access_token' => 'old-access', 'expires_at' => 1000]);

        $this->expectExceptionMessage('Device authorization was denied.');
        try {
            $this->authenticator([
                self::json($this->deviceAuthorization()),
                self::json(['error' => 'access_denied'], 400),
            ], 1000, $storage)->authenticate(true);
        } finally {
            self::assertSame('old-access', $storage->read()['access_token']);
        }
    }

    public function testForceIgnoresUsableStoredCredentialsAndUsesDeviceFlow(): void
    {
        $storage = $this->storage();
        $storage->write([
            'access_token' => 'old-access',
            'expires_at' => 2000,
        ]);

        $result = $this->authenticator([
            self::json($this->deviceAuthorization()),
            self::json(['access_token' => 'new-access', 'token_type' => 'Bearer']),
        ], 1000, $storage)->authenticate(true, true);

        self::assertSame('device', $result->method);
        self::assertSame('new-access', $storage->read()['access_token']);
    }

    public function testInteractiveAuthenticationWithoutCredentialsUsesDeviceFlow(): void
    {
        $result = $this->authenticator([
            self::json($this->deviceAuthorization()),
            self::json(['access_token' => 'device-access', 'token_type' => 'Bearer']),
        ], 1000)->authenticate(true);

        self::assertSame('device', $result->method);
        self::assertSame('device-access', $result->accessToken);
    }

    public function testNonInteractiveAuthenticationWithoutCredentialsFailsInAControlledWay(): void
    {
        $this->expectException(AuthenticationRequiredException::class);
        $this->authenticator([], 1000)->authenticate(false);
    }

    /**
     * @param list<Response> $responses
     */
    private function authenticator(
        array $responses,
        int $time,
        ?TokenStorage $storage = null,
    ): Authenticator {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $storage ??= $this->storage();
        $now = static fn (): float => $time;

        return new Authenticator(
            new DeviceAuthorizationClient(
                new OAuthConfiguration(
                    'https://accounts.example.test/oauth/device',
                    'https://accounts.example.test/oauth/token',
                    'drupalorg-cli',
                    [],
                ),
                $stack,
            ),
            $storage,
            60,
            $now,
            static function (int $seconds): void {
            },
        );
    }

    private function storage(): TokenStorage
    {
        return new TokenStorage($this->workDirectory . '/config');
    }

    /**
     * @return array<string, mixed>
     */
    private function deviceAuthorization(): array
    {
        return [
            'device_code' => 'device-test',
            'user_code' => 'ABCD-EFGH',
            'verification_uri' => 'https://accounts.example.test/device',
            'expires_in' => 600,
            'interval' => 5,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body, int $status = 200): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
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
