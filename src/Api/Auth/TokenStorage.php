<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Auth;

final class TokenStorage
{

    private const FILE_NAME = 'auth.json';

    /**
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'access_token',
        'refresh_token',
        'expires_at',
        'token_type',
        'scope',
    ];

    private string $authFile;

    public function __construct(
        private readonly string $configDirectory,
    ) {
        if ($configDirectory === '') {
            throw new \InvalidArgumentException('The configuration directory must not be empty.');
        }

        $this->authFile = $configDirectory . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /**
     * @return array{
     *   access_token: string,
     *   refresh_token?: string,
     *   expires_at?: int,
     *   token_type?: string,
     *   scope?: string
     * }|null
     */
    public function read(): ?array
    {
        if (!file_exists($this->authFile)) {
            return null;
        }

        if (!is_file($this->authFile) || !is_readable($this->authFile)) {
            throw new \RuntimeException(sprintf('Could not read credentials file: %s', $this->authFile));
        }

        $contents = file_get_contents($this->authFile);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Could not read credentials file: %s', $this->authFile));
        }

        try {
            $credentials = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Could not parse stored credentials.', 0, $exception);
        }

        return $this->validateCredentials($credentials);
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
    public function write(array $credentials): void
    {
        $credentials = $this->validateCredentials($credentials);

        try {
            $contents = json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Could not encode credentials.', 0, $exception);
        }

        $this->ensureConfigDirectory();
        $temporaryFile = tempnam($this->configDirectory, '.auth-');
        if ($temporaryFile === false) {
            throw new \RuntimeException('Could not create temporary credentials file.');
        }

        try {
            if (file_put_contents($temporaryFile, $contents, LOCK_EX) === false) {
                throw new \RuntimeException('Could not write temporary credentials file.');
            }

            @chmod($temporaryFile, 0600);

            if (!rename($temporaryFile, $this->authFile)) {
                throw new \RuntimeException('Could not store credentials.');
            }
        } finally {
            if (file_exists($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    public function delete(): void
    {
        if (!file_exists($this->authFile)) {
            return;
        }

        if (!unlink($this->authFile)) {
            throw new \RuntimeException('Could not delete stored credentials.');
        }
    }

    private function ensureConfigDirectory(): void
    {
        if (!is_dir($this->configDirectory)
            && !mkdir($this->configDirectory, 0700, true)
            && !is_dir($this->configDirectory)) {
            throw new \RuntimeException(sprintf('Could not create configuration directory: %s', $this->configDirectory));
        }

        @chmod($this->configDirectory, 0700);
    }

    /**
     * @param mixed $credentials
     * @return array{
     *   access_token: string,
     *   refresh_token?: string,
     *   expires_at?: int,
     *   token_type?: string,
     *   scope?: string
     * }
     */
    private function validateCredentials(mixed $credentials): array
    {
        if (!is_array($credentials) || array_is_list($credentials)) {
            throw new \RuntimeException('Stored credentials must be a JSON object.');
        }

        foreach (array_keys($credentials) as $key) {
            if (!is_string($key) || !in_array($key, self::ALLOWED_KEYS, true)) {
                throw new \RuntimeException('Stored credentials contain an unsupported field.');
            }
        }

        if (!array_key_exists('access_token', $credentials)
            || !is_string($credentials['access_token'])
            || trim($credentials['access_token']) === '') {
            throw new \RuntimeException('Stored credentials require a non-empty access_token.');
        }

        if (array_key_exists('refresh_token', $credentials)
            && (!is_string($credentials['refresh_token']) || trim($credentials['refresh_token']) === '')) {
            throw new \RuntimeException('Stored refresh_token must be a non-empty string.');
        }

        if (array_key_exists('expires_at', $credentials) && !is_int($credentials['expires_at'])) {
            throw new \RuntimeException('Stored expires_at must be an integer.');
        }

        foreach (['token_type', 'scope'] as $field) {
            if (array_key_exists($field, $credentials) && !is_string($credentials[$field])) {
                throw new \RuntimeException(sprintf('Stored %s must be a string.', $field));
            }
        }

        /** @var array{
         *   access_token: string,
         *   refresh_token?: string,
         *   expires_at?: int,
         *   token_type?: string,
         *   scope?: string
         * } $credentials
         */
        return $credentials;
    }
}
