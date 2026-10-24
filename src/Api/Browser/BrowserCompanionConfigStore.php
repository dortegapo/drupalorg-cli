<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

final class BrowserCompanionConfigStore
{
    private const VERSION = 1;

    public function __construct(private readonly ?string $path = null)
    {
    }

    public function getBrowserFamily(): ?BrowserFamily
    {
        $configuration = $this->read();
        $value = $configuration['browser_companion']['browser_family'] ?? null;
        if (!is_string($value)) {
            return null;
        }

        return BrowserFamily::tryFrom($value);
    }

    public function getInstallation(): ?BrowserCompanionInstallation
    {
        if ($this->getBrowserFamily() === null) {
            return null;
        }

        $configuration = $this->read();
        $value = $configuration['browser_companion']['installation'] ?? BrowserCompanionInstallation::STORE->value;
        if (!is_string($value)) {
            return null;
        }

        return BrowserCompanionInstallation::tryFrom($value);
    }

    public function save(
        BrowserFamily $browserFamily,
        BrowserCompanionInstallation $installation,
    ): void {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the browser companion configuration directory.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($directory, 0700);
        }

        $contents = json_encode([
            'version' => self::VERSION,
            'browser_companion' => [
                'browser_family' => $browserFamily->value,
                'installation' => $installation->value,
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temporaryPath = tempnam($directory, '.browser-companion-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Could not create the browser companion configuration file.');
        }

        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
                throw new \RuntimeException('Could not save the browser companion configuration.');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($temporaryPath, 0600);
            }
            if (!rename($temporaryPath, $path)) {
                if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
                    unlink($path);
                    if (rename($temporaryPath, $path)) {
                        return;
                    }
                }
                throw new \RuntimeException('Could not replace the browser companion configuration.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, 0600);
        }
    }

    public function reset(): void
    {
        $path = $this->path();
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Could not reset the browser companion configuration.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        try {
            $configuration = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($configuration)) {
            return [];
        }
        $version = $configuration['version'] ?? self::VERSION;
        if (!is_int($version) || $version !== self::VERSION) {
            return [];
        }
        if (!is_array($configuration['browser_companion'] ?? null)) {
            return [];
        }

        return $configuration;
    }

    private function path(): string
    {
        return $this->path ?? self::defaultPath();
    }

    public static function defaultPath(): string
    {
        $osFamily = strtolower(PHP_OS_FAMILY);
        if ($osFamily === 'windows') {
            $baseDirectory = getenv('APPDATA');
            if (!is_string($baseDirectory) || $baseDirectory === '') {
                $userProfile = getenv('USERPROFILE');
                $baseDirectory = is_string($userProfile) && $userProfile !== ''
                    ? $userProfile . '\\AppData\\Roaming'
                    : sys_get_temp_dir();
            }
        } else {
            $baseDirectory = getenv('XDG_CONFIG_HOME');
            if (!is_string($baseDirectory) || $baseDirectory === '') {
                $home = getenv('HOME');
                $baseDirectory = is_string($home) && $home !== ''
                    ? $home . '/.config'
                    : sys_get_temp_dir() . '/.config';
            }
        }

        return rtrim($baseDirectory, '/\\') . DIRECTORY_SEPARATOR . 'drupalorg-cli'
            . DIRECTORY_SEPARATOR . 'browser-companion.json';
    }
}
