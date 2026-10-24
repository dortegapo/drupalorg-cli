<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

final class BrowserCompanionConfigStore
{
    public function __construct(private readonly ?string $path = null)
    {
    }

    public function getBrowserFamily(): ?string
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        try {
            $configuration = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($configuration) || !is_array($configuration['browser_companion'] ?? null)) {
            return null;
        }

        $browserFamily = $configuration['browser_companion']['browser_family'] ?? null;

        return is_string($browserFamily) && trim($browserFamily) !== '' ? $browserFamily : null;
    }

    public function saveBrowserFamily(string $browserFamily): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the browser companion configuration directory.');
        }

        $contents = json_encode([
            'browser_companion' => [
                'browser_family' => $browserFamily,
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Could not save the browser companion configuration.');
        }
        chmod($path, 0600);
    }

    public function reset(): void
    {
        $path = $this->path();
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Could not reset the browser companion configuration.');
        }
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
