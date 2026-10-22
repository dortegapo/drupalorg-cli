<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Auth;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Auth\DeviceAuthorizationClient;
use mglaman\DrupalOrg\Auth\OAuthConfiguration;
use mglaman\DrupalOrg\Auth\RefreshTokenRejectedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(DeviceAuthorizationClient::class)]
class DeviceAuthorizationClientTest extends TestCase
{
    /**
     * @var array<int, array{request: RequestInterface}>
     */
    private array $history = [];

    private MockHandler $responses;

    public function testSendsTheDeviceAuthorizationRequest(): void
    {
        $client = $this->client(
            ['project:create', 'profile'],
            self::json($this->validResponse())
        );

        $client->requestDeviceAuthorization();

        $request = $this->sentRequest();
        parse_str((string) $request->getBody(), $formParams);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://accounts.example.test/oauth/device', (string) $request->getUri());
        self::assertSame('drupalorg-cli', $formParams['client_id']);
        self::assertSame('project:create profile', $formParams['scope']);
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('DrupalOrgCli/0.0.1', $request->getHeaderLine('User-Agent'));
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertFalse($request->hasHeader('Authorization'));
    }

    public function testReturnsACompleteDeviceAuthorizationResponse(): void
    {
        $response = $this->validResponse();
        $client = $this->client(['project:create'], self::json($response));

        self::assertSame($response, $client->requestDeviceAuthorization());
    }

    public function testUsesTheDefaultIntervalWhenItIsAbsent(): void
    {
        $response = $this->validResponse();
        unset($response['interval']);

        $result = $this->client(['project:create'], self::json($response))->requestDeviceAuthorization();

        self::assertSame(5, $result['interval']);
    }

    public function testDoesNotRequireVerificationUriComplete(): void
    {
        $response = $this->validResponse();
        unset($response['verification_uri_complete']);

        $result = $this->client(['project:create'], self::json($response))->requestDeviceAuthorization();

        self::assertArrayNotHasKey('verification_uri_complete', $result);
    }

    public function testFailsForInvalidJson(): void
    {
        $client = $this->client(['project:create'], new Response(200, ['Content-Type' => 'application/json'], 'not json'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not parse device authorization response.');
        $client->requestDeviceAuthorization();
    }

    public function testReportsOAuthErrors(): void
    {
        $client = $this->client(
            ['project:create'],
            self::json(['error' => 'invalid_scope'], 400)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device authorization failed: invalid_scope.');
        $client->requestDeviceAuthorization();
    }

    public function testReportsGenericHttpErrors(): void
    {
        $client = $this->client(['project:create'], new Response(500, [], 'Server error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device authorization failed with HTTP 500.');
        $client->requestDeviceAuthorization();
    }

    public function testFailsWhenARequiredFieldIsMissing(): void
    {
        $response = $this->validResponse();
        unset($response['device_code']);

        $client = $this->client(['project:create'], self::json($response));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device authorization response requires a non-empty device_code string.');
        $client->requestDeviceAuthorization();
    }

    public function testFailsWhenExpiresInHasAnInvalidType(): void
    {
        $response = $this->validResponse();
        $response['expires_in'] = '600';

        $client = $this->client(['project:create'], self::json($response));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device authorization response requires a positive expires_in integer.');
        $client->requestDeviceAuthorization();
    }

    /**
     * @param int|string $interval
     */
    #[DataProvider('invalidIntervalProvider')]
    public function testFailsWhenIntervalIsInvalid(int|string $interval): void
    {
        $response = $this->validResponse();
        $response['interval'] = $interval;
        $client = $this->client(['project:create'], self::json($response));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device authorization response requires a positive interval integer.');
        $client->requestDeviceAuthorization();
    }

    /**
     * @return iterable<string, array{0: int|string}>
     */
    public static function invalidIntervalProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'string' => ['5'];
    }

    public function testOmitsScopeWhenConfigurationHasNoScopes(): void
    {
        $client = $this->client([], self::json($this->validResponse()));

        $client->requestDeviceAuthorization();

        parse_str((string) $this->sentRequest()->getBody(), $formParams);
        self::assertSame('drupalorg-cli', $formParams['client_id']);
        self::assertArrayNotHasKey('scope', $formParams);
    }

    public function testSendsTheTokenRequest(): void
    {
        $client = $this->client(['project:create'], self::json($this->minimalTokenResponse()));

        $client->requestToken('device-test');

        $request = $this->sentRequest();
        parse_str((string) $request->getBody(), $formParams);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://accounts.example.test/oauth/token', (string) $request->getUri());
        self::assertSame('urn:ietf:params:oauth:grant-type:device_code', $formParams['grant_type']);
        self::assertSame('device-test', $formParams['device_code']);
        self::assertSame('drupalorg-cli', $formParams['client_id']);
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertFalse($request->hasHeader('Authorization'));
    }

    public function testReturnsAMinimalTokenResponse(): void
    {
        $result = $this->client(['project:create'], self::json($this->minimalTokenResponse()))
            ->requestToken('device-test');

        self::assertSame([
            'status' => 'success',
            'access_token' => 'access-test',
            'token_type' => 'Bearer',
        ], $result);
    }

    public function testReturnsACompleteTokenResponse(): void
    {
        $response = [
            'access_token' => 'access-test',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'refresh-test',
            'scope' => 'project:create profile',
        ];

        $result = $this->client(['project:create'], self::json($response))->requestToken('device-test');

        self::assertSame(['status' => 'success', ...$response], $result);
    }

    public function testSendsTheRefreshTokenRequestWithoutDeviceCodeOrSecret(): void
    {
        $client = $this->client(['project:create'], self::json($this->minimalTokenResponse()));

        $result = $client->requestRefreshToken('refresh-test');

        self::assertSame($this->minimalTokenResponse(), $result);
        parse_str((string) $this->sentRequest()->getBody(), $formParams);
        self::assertSame('refresh_token', $formParams['grant_type']);
        self::assertSame('refresh-test', $formParams['refresh_token']);
        self::assertSame('drupalorg-cli', $formParams['client_id']);
        self::assertArrayNotHasKey('device_code', $formParams);
        self::assertArrayNotHasKey('client_secret', $formParams);
    }

    public function testClassifiesInvalidGrantAsARejectedRefreshToken(): void
    {
        $client = $this->client(['project:create'], self::json(['error' => 'invalid_grant'], 400));

        $this->expectException(RefreshTokenRejectedException::class);
        $this->expectExceptionMessage('The stored refresh token was rejected.');
        $client->requestRefreshToken('refresh-test');
    }

    public function testReportsRefreshServerFailuresAsOperationalErrors(): void
    {
        $client = $this->client(['project:create'], new Response(500, [], 'Server error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refresh token request failed with HTTP 500.');
        $client->requestRefreshToken('refresh-test');
    }

    /**
     * @param 'authorization_pending'|'slow_down'|'access_denied'|'expired_token' $error
     */
    #[DataProvider('deviceAuthorizationErrorProvider')]
    public function testReturnsKnownDeviceAuthorizationErrors(string $error): void
    {
        $result = $this->client(['project:create'], self::json(['error' => $error], 400))
            ->requestToken('device-test');

        self::assertSame(['status' => $error], $result);
    }

    /**
     * @return iterable<string, array{0: 'authorization_pending'|'slow_down'|'access_denied'|'expired_token'}>
     */
    public static function deviceAuthorizationErrorProvider(): iterable
    {
        yield 'authorization pending' => ['authorization_pending'];
        yield 'slow down' => ['slow_down'];
        yield 'access denied' => ['access_denied'];
        yield 'expired token' => ['expired_token'];
    }

    public function testFailsForAnUnknownOAuthError(): void
    {
        $client = $this->client(['project:create'], self::json(['error' => 'invalid_client'], 400));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token request failed: invalid_client.');
        $client->requestToken('device-test');
    }

    public function testFailsForAGenericTokenHttpError(): void
    {
        $client = $this->client(['project:create'], new Response(503, [], 'Service unavailable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token request failed with HTTP 503.');
        $client->requestToken('device-test');
    }

    public function testFailsForInvalidTokenJson(): void
    {
        $client = $this->client(['project:create'], new Response(200, ['Content-Type' => 'application/json'], 'not-json'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not parse token response.');
        $client->requestToken('device-test');
    }

    /**
     * @param 'access_token'|'token_type' $field
     */
    #[DataProvider('missingTokenFieldProvider')]
    public function testFailsWhenATokenFieldIsMissing(string $field): void
    {
        $response = $this->minimalTokenResponse();
        unset($response[$field]);
        $client = $this->client(['project:create'], self::json($response));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Token response requires a non-empty %s string.', $field));
        $client->requestToken('device-test');
    }

    /**
     * @return iterable<string, array{0: 'access_token'|'token_type'}>
     */
    public static function missingTokenFieldProvider(): iterable
    {
        yield 'access token' => ['access_token'];
        yield 'token type' => ['token_type'];
    }

    /**
     * @param array<string, mixed> $response
     */
    #[DataProvider('invalidTokenResponseProvider')]
    public function testFailsForInvalidTokenResponseValues(array $response): void
    {
        $client = $this->client(['project:create'], self::json($response));

        $this->expectException(\RuntimeException::class);
        $client->requestToken('device-test');
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidTokenResponseProvider(): iterable
    {
        yield 'non-string access token' => [['access_token' => 123, 'token_type' => 'Bearer']];
        yield 'non-integer expires in' => [['access_token' => 'access-test', 'token_type' => 'Bearer', 'expires_in' => '3600']];
    }

    /**
     * @param ''|'   ' $deviceCode
     */
    #[DataProvider('emptyDeviceCodeProvider')]
    public function testRejectsEmptyDeviceCodesBeforeMakingARequest(string $deviceCode): void
    {
        $client = $this->client(['project:create'], self::json($this->minimalTokenResponse()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('deviceCode must not be empty.');
        try {
            $client->requestToken($deviceCode);
        } finally {
            self::assertCount(0, $this->history);
        }
    }

    /**
     * @return iterable<string, array{0: ''|'   '}>
     */
    public static function emptyDeviceCodeProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    public function testWaitForTokenReturnsSuccessAfterTheFirstPoll(): void
    {
        $client = $this->client(['project:create'], self::json($this->minimalTokenResponse()));
        $time = 1000.0;
        $sleeps = [];

        $result = $client->waitForToken(
            $this->deviceAuthorization(),
            static function (int $seconds) use (&$time, &$sleeps): void {
                $sleeps[] = $seconds;
                $time += $seconds;
            },
            static function () use (&$time): float {
                return $time;
            },
        );

        self::assertSame([5], $sleeps);
        self::assertSame(1, count($this->history));
        self::assertSame($this->minimalTokenResponse(), $result);
    }

    public function testWaitForTokenContinuesWhenAuthorizationIsPending(): void
    {
        $client = $this->client(
            ['project:create'],
            self::json(['error' => 'authorization_pending'], 400),
            self::json($this->minimalTokenResponse()),
        );
        $time = 1000.0;
        $sleeps = [];

        $client->waitForToken(
            $this->deviceAuthorization(),
            static function (int $seconds) use (&$time, &$sleeps): void {
                $sleeps[] = $seconds;
                $time += $seconds;
            },
            static function () use (&$time): float {
                return $time;
            },
        );

        self::assertSame([5, 5], $sleeps);
        self::assertSame(2, count($this->history));
    }

    public function testWaitForTokenIncreasesTheIntervalAfterSlowDown(): void
    {
        $client = $this->client(
            ['project:create'],
            self::json(['error' => 'slow_down'], 400),
            self::json(['error' => 'authorization_pending'], 400),
            self::json($this->minimalTokenResponse()),
        );
        $time = 1000.0;
        $sleeps = [];

        $client->waitForToken(
            $this->deviceAuthorization(),
            static function (int $seconds) use (&$time, &$sleeps): void {
                $sleeps[] = $seconds;
                $time += $seconds;
            },
            static function () use (&$time): float {
                return $time;
            },
        );

        self::assertSame([5, 10, 10], $sleeps);
        self::assertSame(3, count($this->history));
    }

    public function testWaitForTokenAccumulatesSlowDownIntervals(): void
    {
        $client = $this->client(
            ['project:create'],
            self::json(['error' => 'slow_down'], 400),
            self::json(['error' => 'slow_down'], 400),
            self::json($this->minimalTokenResponse()),
        );
        $time = 1000.0;
        $sleeps = [];

        $client->waitForToken(
            $this->deviceAuthorization(),
            static function (int $seconds) use (&$time, &$sleeps): void {
                $sleeps[] = $seconds;
                $time += $seconds;
            },
            static function () use (&$time): float {
                return $time;
            },
        );

        self::assertSame([5, 10, 15], $sleeps);
        self::assertSame(3, count($this->history));
    }

    public function testWaitForTokenFailsWhenAuthorizationIsDenied(): void
    {
        $client = $this->client(
            ['project:create'],
            self::json(['error' => 'authorization_pending'], 400),
            self::json(['error' => 'access_denied'], 400),
        );
        $time = 1000.0;
        $sleeps = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device authorization was denied.');
        try {
            $client->waitForToken(
                $this->deviceAuthorization(),
                static function (int $seconds) use (&$time, &$sleeps): void {
                    $sleeps[] = $seconds;
                    $time += $seconds;
                },
                static function () use (&$time): float {
                    return $time;
                },
            );
        } finally {
            self::assertSame([5, 5], $sleeps);
            self::assertSame(2, count($this->history));
        }
    }

    public function testWaitForTokenFailsWhenTheServerExpiresTheAuthorization(): void
    {
        $client = $this->client(['project:create'], self::json(['error' => 'expired_token'], 400));
        $time = 1000.0;
        $sleeps = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device authorization expired.');
        try {
            $client->waitForToken(
                $this->deviceAuthorization(),
                static function (int $seconds) use (&$time, &$sleeps): void {
                    $sleeps[] = $seconds;
                    $time += $seconds;
                },
                static function () use (&$time): float {
                    return $time;
                },
            );
        } finally {
            self::assertSame([5], $sleeps);
            self::assertSame(1, count($this->history));
        }
    }

    public function testWaitForTokenFailsWhenTheAuthorizationExpiresLocally(): void
    {
        $client = $this->client(
            ['project:create'],
            self::json(['error' => 'authorization_pending'], 400),
            self::json(['error' => 'authorization_pending'], 400),
        );
        $time = 1000.0;
        $sleeps = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device authorization expired.');
        try {
            $client->waitForToken(
                $this->deviceAuthorization(12),
                static function (int $seconds) use (&$time, &$sleeps): void {
                    $sleeps[] = $seconds;
                    $time += $seconds;
                },
                static function () use (&$time): float {
                    return $time;
                },
            );
        } finally {
            self::assertSame([5, 5], $sleeps);
            self::assertSame(2, count($this->history));
        }
    }

    public function testWaitForTokenFailsWhenSlowDownWouldPassTheDeadline(): void
    {
        $client = $this->client(['project:create'], self::json(['error' => 'slow_down'], 400));
        $time = 1000.0;
        $sleeps = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device authorization expired.');
        try {
            $client->waitForToken(
                $this->deviceAuthorization(12),
                static function (int $seconds) use (&$time, &$sleeps): void {
                    $sleeps[] = $seconds;
                    $time += $seconds;
                },
                static function () use (&$time): float {
                    return $time;
                },
            );
        } finally {
            self::assertSame([5], $sleeps);
            self::assertSame(1, count($this->history));
        }
    }

    /**
     * @param array<string, mixed> $deviceAuthorization
     */
    #[DataProvider('invalidDeviceAuthorizationProvider')]
    public function testWaitForTokenRejectsInvalidDeviceAuthorization(array $deviceAuthorization): void
    {
        $client = $this->client(['project:create'], self::json($this->minimalTokenResponse()));
        $time = 1000.0;
        $sleeps = [];

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->waitForToken(
                $deviceAuthorization,
                static function (int $seconds) use (&$time, &$sleeps): void {
                    $sleeps[] = $seconds;
                    $time += $seconds;
                },
                static function () use (&$time): float {
                    return $time;
                },
            );
        } finally {
            self::assertSame([], $sleeps);
            self::assertCount(0, $this->history);
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidDeviceAuthorizationProvider(): iterable
    {
        $deviceAuthorization = [
            'device_code' => 'device-test',
            'user_code' => 'ABCD',
            'verification_uri' => 'https://example.test/device',
            'expires_in' => 600,
            'interval' => 5,
        ];

        $emptyDeviceCode = $deviceAuthorization;
        $emptyDeviceCode['device_code'] = '';
        yield 'empty device code' => [$emptyDeviceCode];

        $zeroExpiresIn = $deviceAuthorization;
        $zeroExpiresIn['expires_in'] = 0;
        yield 'zero expires in' => [$zeroExpiresIn];

        $negativeExpiresIn = $deviceAuthorization;
        $negativeExpiresIn['expires_in'] = -1;
        yield 'negative expires in' => [$negativeExpiresIn];

        $zeroInterval = $deviceAuthorization;
        $zeroInterval['interval'] = 0;
        yield 'zero interval' => [$zeroInterval];

        $negativeInterval = $deviceAuthorization;
        $negativeInterval['interval'] = -1;
        yield 'negative interval' => [$negativeInterval];
    }

    public function testWaitForTokenPropagatesGenericHttpErrors(): void
    {
        $client = $this->client(
            ['project:create'],
            self::json(['error' => 'authorization_pending'], 400),
            new Response(503, [], 'Service unavailable'),
        );
        $time = 1000.0;
        $sleeps = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token request failed with HTTP 503.');
        try {
            $client->waitForToken(
                $this->deviceAuthorization(),
                static function (int $seconds) use (&$time, &$sleeps): void {
                    $sleeps[] = $seconds;
                    $time += $seconds;
                },
                static function () use (&$time): float {
                    return $time;
                },
            );
        } finally {
            self::assertSame([5, 5], $sleeps);
            self::assertSame(2, count($this->history));
        }
    }

    /**
     * @param list<string> $scopes
     */
    private function client(array $scopes, Response ...$responses): DeviceAuthorizationClient
    {
        $this->responses = new MockHandler($responses);
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->history));
        return new DeviceAuthorizationClient(
            new OAuthConfiguration(
                'https://accounts.example.test/oauth/device',
                'https://accounts.example.test/oauth/token',
                'drupalorg-cli',
                $scopes,
            ),
            $stack,
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
    private function validResponse(): array
    {
        return [
            'device_code' => 'device-test',
            'user_code' => 'ABCD-EFGH',
            'verification_uri' => 'https://accounts.example.test/device',
            'verification_uri_complete' => 'https://accounts.example.test/device?user_code=ABCD-EFGH',
            'expires_in' => 600,
            'interval' => 7,
        ];
    }

    /**
     * @return array{
     *   device_code: string,
     *   user_code: string,
     *   verification_uri: string,
     *   expires_in: int,
     *   interval: int
     * }
     */
    private function deviceAuthorization(int $expiresIn = 600): array
    {
        return [
            'device_code' => 'device-test',
            'user_code' => 'ABCD',
            'verification_uri' => 'https://example.test/device',
            'expires_in' => $expiresIn,
            'interval' => 5,
        ];
    }

    /**
     * @return array{access_token: string, token_type: string}
     */
    private function minimalTokenResponse(): array
    {
        return [
            'access_token' => 'access-test',
            'token_type' => 'Bearer',
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

    private function sentRequest(): RequestInterface
    {
        self::assertArrayHasKey(0, $this->history);
        return $this->history[0]['request'];
    }
}
