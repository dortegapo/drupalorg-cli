<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Auth;

final class Authenticator
{
    public function __construct(
        private readonly DeviceAuthorizationClient $oauthClient,
        private readonly TokenStorage $tokenStorage,
        private readonly int $expiryLeeway = 60,
        ?\Closure $now = null,
        private readonly ?\Closure $sleep = null,
    ) {
        if ($this->expiryLeeway < 0) {
            throw new \InvalidArgumentException('expiryLeeway must not be negative.');
        }

        $this->now = $now ?? static fn (): float => microtime(true);
    }

    private readonly \Closure $now;

    /**
     * Authenticate without exposing OAuth protocol details to the caller.
     *
     * @param callable(array{
     *     verification_uri: string,
     *     verification_uri_complete?: string,
     *     user_code: string,
     *     expires_in: int
     * }): void|null $onDeviceAuthorization
     */
    public function authenticate(
        bool $interactive = true,
        bool $force = false,
        ?callable $onDeviceAuthorization = null,
    ): AuthenticationResult {
        if (!$force) {
            $storedCredentials = $this->tokenStorage->read();
            if ($storedCredentials !== null && $this->isUsable($storedCredentials)) {
                return new AuthenticationResult($storedCredentials['access_token'], 'stored');
            }

            if ($storedCredentials !== null && isset($storedCredentials['refresh_token'])) {
                try {
                    $credentials = $this->oauthClient->requestRefreshToken($storedCredentials['refresh_token']);
                    $this->tokenStorage->write($this->storedCredentialsFromToken(
                        $credentials,
                        $storedCredentials,
                        true,
                    ));

                    return new AuthenticationResult($credentials['access_token'], 'refreshed');
                } catch (RefreshTokenRejectedException) {
                    if (!$interactive) {
                        throw new AuthenticationRequiredException();
                    }
                }
            }
        }

        if (!$interactive) {
            throw new AuthenticationRequiredException();
        }

        $deviceAuthorization = $this->oauthClient->requestDeviceAuthorization();
        if ($onDeviceAuthorization !== null) {
            $publicAuthorization = [
                'verification_uri' => $deviceAuthorization['verification_uri'],
                'user_code' => $deviceAuthorization['user_code'],
                'expires_in' => $deviceAuthorization['expires_in'],
            ];
            if (isset($deviceAuthorization['verification_uri_complete'])) {
                $publicAuthorization['verification_uri_complete'] = $deviceAuthorization['verification_uri_complete'];
            }

            $onDeviceAuthorization($publicAuthorization);
        }

        $credentials = $this->oauthClient->waitForToken(
            $deviceAuthorization,
            $this->sleep,
            $this->now,
        );
        $this->tokenStorage->write($this->storedCredentialsFromToken($credentials));

        return new AuthenticationResult($credentials['access_token'], 'device');
    }

    /**
     * @param array{
     *   access_token: string,
     *   refresh_token?: string,
     *   expires_at?: int,
     *   token_type?: string,
     *   scope?: string
     * } $credentials
     */
    private function isUsable(array $credentials): bool
    {
        return !array_key_exists('expires_at', $credentials)
            || $credentials['expires_at'] > $this->nowAsInteger() + $this->expiryLeeway;
    }

    /**
     * @param array{
     *   access_token: string,
     *   token_type: string,
     *   expires_in?: int,
     *   refresh_token?: string,
     *   scope?: string
     * } $token
     * @param array{
     *   access_token: string,
     *   refresh_token?: string,
     *   expires_at?: int,
     *   token_type?: string,
     *   scope?: string
     * }|null $previous
     * @return array{
     *   access_token: string,
     *   refresh_token?: string,
     *   expires_at?: int,
     *   token_type: string,
     *   scope?: string
     * }
     */
    private function storedCredentialsFromToken(array $token, ?array $previous = null, bool $preserveRefreshToken = false): array
    {
        $credentials = [
            'access_token' => $token['access_token'],
        ];
        if (isset($token['refresh_token'])) {
            $credentials['refresh_token'] = $token['refresh_token'];
        } elseif ($preserveRefreshToken && $previous !== null && isset($previous['refresh_token'])) {
            $credentials['refresh_token'] = $previous['refresh_token'];
        }
        if (isset($token['expires_in'])) {
            $credentials['expires_at'] = $this->nowAsInteger() + $token['expires_in'];
        }
        $credentials['token_type'] = $token['token_type'];
        if (isset($token['scope'])) {
            $credentials['scope'] = $token['scope'];
        } elseif ($preserveRefreshToken && $previous !== null && isset($previous['scope'])) {
            $credentials['scope'] = $previous['scope'];
        }

        return $credentials;
    }

    private function nowAsInteger(): int
    {
        return (int) ($this->now)();
    }
}
