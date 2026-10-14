<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Auth;

use GuzzleHttp\HandlerStack;

final class DeviceAuthorizationClient
{

    /**
     * @var list<string>
     */
    private const DEVICE_AUTHORIZATION_ERRORS = [
        'authorization_pending',
        'slow_down',
        'access_denied',
        'expired_token',
    ];

    private \GuzzleHttp\Client $client;

    public function __construct(
        private readonly OAuthConfiguration $configuration,
        ?HandlerStack $handler = null,
    ) {
        $this->client = new \GuzzleHttp\Client([
            'handler' => $handler ?? HandlerStack::create(),
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'DrupalOrgCli/0.0.1',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @return array{
     *   device_code: string,
     *   user_code: string,
     *   verification_uri: string,
     *   verification_uri_complete?: string,
     *   expires_in: int,
     *   interval: int
     * }
     */
    public function requestDeviceAuthorization(): array
    {
        $formParams = ['client_id' => $this->configuration->clientId];
        if ($this->configuration->scopes !== []) {
            $formParams['scope'] = implode(' ', $this->configuration->scopes);
        }

        $response = $this->client->request('POST', $this->configuration->deviceAuthorizationEndpoint, [
            'form_params' => $formParams,
        ]);
        $contents = (string) $response->getBody();

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $error = $this->oauthError($contents);
            if ($error !== null) {
                throw new \RuntimeException(sprintf('Device authorization failed: %s.', $error));
            }

            throw new \RuntimeException(sprintf(
                'Device authorization failed with HTTP %d.',
                $response->getStatusCode()
            ));
        }

        try {
            $responseData = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Could not parse device authorization response.', 0, $exception);
        }

        return $this->validateResponse($responseData);
    }

    /**
     * @return array{
     *   status: 'success'|'authorization_pending'|'slow_down'|'access_denied'|'expired_token',
     *   access_token?: string,
     *   refresh_token?: string,
     *   expires_in?: int,
     *   token_type?: string,
     *   scope?: string
     * }
     */
    public function requestToken(string $deviceCode): array
    {
        if (trim($deviceCode) === '') {
            throw new \InvalidArgumentException('deviceCode must not be empty.');
        }

        $response = $this->client->request('POST', $this->configuration->tokenEndpoint, [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'device_code' => $deviceCode,
                'client_id' => $this->configuration->clientId,
            ],
        ]);
        $contents = (string) $response->getBody();

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $error = $this->oauthError($contents);
            if ($error !== null) {
                if (in_array($error, self::DEVICE_AUTHORIZATION_ERRORS, true)) {
                    return ['status' => $error];
                }

                throw new \RuntimeException(sprintf('Token request failed: %s.', $error));
            }

            throw new \RuntimeException(sprintf(
                'Token request failed with HTTP %d.',
                $response->getStatusCode()
            ));
        }

        try {
            $responseData = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Could not parse token response.', 0, $exception);
        }

        return $this->validateTokenResponse($responseData);
    }

    /**
     * @param array{
     *   device_code: string,
     *   user_code: string,
     *   verification_uri: string,
     *   verification_uri_complete?: string,
     *   expires_in: int,
     *   interval: int
     * } $deviceAuthorization
     * @param \Closure(int): void|null $sleep
     * @param \Closure(): float|null $now
     *
     * @return array{
     *   access_token: string,
     *   refresh_token?: string,
     *   expires_in?: int,
     *   token_type: string,
     *   scope?: string
     * }
     */
    public function waitForToken(
        array $deviceAuthorization,
        ?\Closure $sleep = null,
        ?\Closure $now = null,
    ): array {
        $this->validateDeviceAuthorizationForPolling($deviceAuthorization);

        $sleep ??= static function (int $seconds): void {
            sleep($seconds);
        };
        $now ??= static fn (): float => microtime(true);

        $deadline = $now() + $deviceAuthorization['expires_in'];
        $interval = $deviceAuthorization['interval'];

        while (true) {
            $currentTime = $now();
            if ($currentTime >= $deadline || $currentTime + $interval >= $deadline) {
                throw new \RuntimeException('Device authorization expired.');
            }

            $sleep($interval);
            $result = $this->requestToken($deviceAuthorization['device_code']);

            switch ($result['status']) {
                case 'success':
                    unset($result['status']);
                    return $result;

                case 'authorization_pending':
                    continue 2;

                case 'slow_down':
                    $interval += 5;
                    continue 2;

                case 'access_denied':
                    throw new \RuntimeException('Device authorization was denied.');

                case 'expired_token':
                    throw new \RuntimeException('Device authorization expired.');
            }
        }
    }

    private function oauthError(string $contents): ?string
    {
        try {
            $responseData = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($responseData) || !isset($responseData['error']) || !is_string($responseData['error'])) {
            return null;
        }

        $error = trim($responseData['error']);
        return $error === '' ? null : $error;
    }

    /**
     * @param array<string, mixed> $deviceAuthorization
     */
    private function validateDeviceAuthorizationForPolling(array $deviceAuthorization): void
    {
        if (!isset($deviceAuthorization['device_code'])
            || !is_string($deviceAuthorization['device_code'])
            || trim($deviceAuthorization['device_code']) === '') {
            throw new \InvalidArgumentException('deviceAuthorization device_code must be a non-empty string.');
        }

        if (!isset($deviceAuthorization['expires_in'])
            || !is_int($deviceAuthorization['expires_in'])
            || $deviceAuthorization['expires_in'] <= 0) {
            throw new \InvalidArgumentException('deviceAuthorization expires_in must be a positive integer.');
        }

        if (!isset($deviceAuthorization['interval'])
            || !is_int($deviceAuthorization['interval'])
            || $deviceAuthorization['interval'] <= 0) {
            throw new \InvalidArgumentException('deviceAuthorization interval must be a positive integer.');
        }
    }

    /**
     * @param mixed $responseData
     * @return array{
     *   device_code: string,
     *   user_code: string,
     *   verification_uri: string,
     *   verification_uri_complete?: string,
     *   expires_in: int,
     *   interval: int
     * }
     */
    private function validateResponse(mixed $responseData): array
    {
        if (!is_array($responseData) || array_is_list($responseData)) {
            throw new \RuntimeException('Device authorization response must be a JSON object.');
        }

        foreach (['device_code', 'user_code', 'verification_uri'] as $field) {
            if (!isset($responseData[$field])
                || !is_string($responseData[$field])
                || trim($responseData[$field]) === '') {
                throw new \RuntimeException(sprintf(
                    'Device authorization response requires a non-empty %s string.',
                    $field
                ));
            }
        }

        if (!isset($responseData['expires_in'])
            || !is_int($responseData['expires_in'])
            || $responseData['expires_in'] <= 0) {
            throw new \RuntimeException('Device authorization response requires a positive expires_in integer.');
        }

        $interval = $responseData['interval'] ?? 5;
        if (!is_int($interval) || $interval <= 0) {
            throw new \RuntimeException('Device authorization response requires a positive interval integer.');
        }

        if (array_key_exists('verification_uri_complete', $responseData)
            && (!is_string($responseData['verification_uri_complete'])
                || trim($responseData['verification_uri_complete']) === '')) {
            throw new \RuntimeException('Device authorization response verification_uri_complete must be a non-empty string.');
        }

        $result = [
            'device_code' => $responseData['device_code'],
            'user_code' => $responseData['user_code'],
            'verification_uri' => $responseData['verification_uri'],
        ];
        if (array_key_exists('verification_uri_complete', $responseData)) {
            $result['verification_uri_complete'] = $responseData['verification_uri_complete'];
        }
        $result['expires_in'] = $responseData['expires_in'];
        $result['interval'] = $interval;

        return $result;
    }

    /**
     * @param mixed $responseData
     * @return array{
     *   status: 'success',
     *   access_token: string,
     *   refresh_token?: string,
     *   expires_in?: int,
     *   token_type: string,
     *   scope?: string
     * }
     */
    private function validateTokenResponse(mixed $responseData): array
    {
        if (!is_array($responseData) || array_is_list($responseData)) {
            throw new \RuntimeException('Token response must be a JSON object.');
        }

        foreach (['access_token', 'token_type'] as $field) {
            if (!isset($responseData[$field])
                || !is_string($responseData[$field])
                || trim($responseData[$field]) === '') {
                throw new \RuntimeException(sprintf('Token response requires a non-empty %s string.', $field));
            }
        }

        if (array_key_exists('refresh_token', $responseData)
            && (!is_string($responseData['refresh_token']) || trim($responseData['refresh_token']) === '')) {
            throw new \RuntimeException('Token response refresh_token must be a non-empty string.');
        }

        if (array_key_exists('expires_in', $responseData)
            && (!is_int($responseData['expires_in']) || $responseData['expires_in'] <= 0)) {
            throw new \RuntimeException('Token response expires_in must be a positive integer.');
        }

        if (array_key_exists('scope', $responseData)
            && (!is_string($responseData['scope']) || trim($responseData['scope']) === '')) {
            throw new \RuntimeException('Token response scope must be a non-empty string.');
        }

        $result = [
            'status' => 'success',
            'access_token' => $responseData['access_token'],
            'token_type' => $responseData['token_type'],
        ];
        foreach (['expires_in', 'refresh_token', 'scope'] as $field) {
            if (array_key_exists($field, $responseData)) {
                $result[$field] = $responseData[$field];
            }
        }

        return $result;
    }
}
