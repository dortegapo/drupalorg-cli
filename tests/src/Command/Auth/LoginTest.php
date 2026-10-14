<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Auth;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Auth\DeviceAuthorizationClient;
use mglaman\DrupalOrg\Auth\OAuthConfiguration;
use mglaman\DrupalOrg\Auth\TokenStorage;
use mglaman\DrupalOrgCli\Command\Auth\Login;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Login::class)]
class LoginTest extends TestCase
{
    private string $configDirectory;

    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/drupalorg-cli-login-' . uniqid();
        $this->configDirectory = $this->workDirectory . '/config';
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDirectory);
    }

    public function testLoginDisplaysInstructionsAndStoresCompleteCredentials(): void
    {
        $sleeps = [];
        $command = $this->command(
            [
                'device_code' => 'device-test',
                'user_code' => 'ABCD-EFGH',
                'verification_uri' => 'https://accounts.example.test/device',
                'verification_uri_complete' => 'https://accounts.example.test/device?user_code=ABCD-EFGH',
                'expires_in' => 600,
                'interval' => 5,
            ],
            [
                'access_token' => 'access-test',
                'refresh_token' => 'refresh-test',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'project:create',
            ],
            static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(0, $exitCode);
        self::assertSame('auth:login', $command->getName());
        self::assertStringContainsString('Authenticate with Drupal.org.', (string) $command->getDescription());
        self::assertStringContainsString(
            'https://accounts.example.test/device?user_code=ABCD-EFGH',
            $tester->getDisplay()
        );
        self::assertStringContainsString('Waiting for authorization...', $tester->getDisplay());
        self::assertStringContainsString('Authentication successful.', $tester->getDisplay());
        self::assertSame([5], $sleeps);
        self::assertStringNotContainsString('device-test', $tester->getDisplay());
        self::assertStringNotContainsString('access-test', $tester->getDisplay());
        self::assertStringNotContainsString('refresh-test', $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());

        $credentials = [
            'access_token' => 'access-test',
            'refresh_token' => 'refresh-test',
            'expires_at' => 1790003600,
            'token_type' => 'Bearer',
            'scope' => 'project:create',
        ];
        self::assertSame($credentials, $this->storage()->read());
        self::assertSame(
            $credentials,
            json_decode((string) file_get_contents($this->authFile()), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testLoginDisplaysTheUriAndUserCodeWhenNoCompleteUriIsProvided(): void
    {
        $command = $this->command(
            [
                'device_code' => 'device-test',
                'user_code' => 'ABCD-EFGH',
                'verification_uri' => 'https://accounts.example.test/device',
                'expires_in' => 600,
                'interval' => 5,
            ],
            [
                'access_token' => 'access-test',
                'token_type' => 'Bearer',
            ],
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('https://accounts.example.test/device', $tester->getDisplay());
        self::assertStringContainsString('ABCD-EFGH', $tester->getDisplay());
        self::assertSame([
            'access_token' => 'access-test',
            'token_type' => 'Bearer',
        ], $this->storage()->read());
    }

    public function testDeniedLoginDoesNotWriteCredentials(): void
    {
        $tester = new CommandTester($this->command(
            $this->deviceAuthorization(),
            ['error' => 'access_denied'],
            null,
            400,
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device authorization was denied.');
        try {
            $tester->execute([]);
        } finally {
            self::assertNull($this->storage()->read());
        }
    }

    public function testExpiredLoginDoesNotWriteCredentials(): void
    {
        $tester = new CommandTester($this->command(
            $this->deviceAuthorization(),
            ['error' => 'expired_token'],
            null,
            400,
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device authorization expired.');
        try {
            $tester->execute([]);
        } finally {
            self::assertNull($this->storage()->read());
        }
    }

    public function testFailedLoginPreservesExistingCredentials(): void
    {
        $this->storage()->write(['access_token' => 'existing-token']);
        $tester = new CommandTester($this->command(
            $this->deviceAuthorization(),
            ['error' => 'access_denied'],
            null,
            400,
        ));

        $this->expectException(\RuntimeException::class);
        try {
            $tester->execute([]);
        } finally {
            self::assertSame(['access_token' => 'existing-token'], $this->storage()->read());
        }
    }

    /**
     * @param array<string, mixed> $deviceAuthorization
     * @param array<string, mixed> $tokenResponse
     * @param \Closure(int): void|null $sleep
     */
    private function command(
        array $deviceAuthorization,
        array $tokenResponse,
        ?\Closure $sleep = null,
        int $tokenStatus = 200,
    ): Login {
        $stack = HandlerStack::create(new MockHandler([
            self::json($deviceAuthorization),
            self::json($tokenResponse, $tokenStatus),
        ]));
        $time = 1790000000.0;

        return new Login(
            new DeviceAuthorizationClient(
                new OAuthConfiguration(
                    'https://accounts.example.test/oauth/device',
                    'https://accounts.example.test/oauth/token',
                    'drupalorg-cli',
                    ['project:create'],
                ),
                $stack,
            ),
            $this->storage(),
            $sleep ?? static function (int $seconds): void {
            },
            static function () use (&$time): float {
                return $time;
            },
        );
    }

    /**
     * @return array{
     *   device_code: string,
     *   user_code: string,
     *   verification_uri: string,
     *   verification_uri_complete: string,
     *   expires_in: int,
     *   interval: int
     * }
     */
    private function deviceAuthorization(): array
    {
        return [
            'device_code' => 'device-test',
            'user_code' => 'ABCD-EFGH',
            'verification_uri' => 'https://accounts.example.test/device',
            'verification_uri_complete' => 'https://accounts.example.test/device?user_code=ABCD-EFGH',
            'expires_in' => 600,
            'interval' => 5,
        ];
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
