<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Auth;

final class ConfigDirectoryResolver
{

    /**
     * @param array<string, string>|null $environment
     */
    public function __construct(
        private readonly ?array $environment = null,
        private readonly ?string $osFamily = null,
    ) {
    }

    public function resolve(): string
    {
        $xdgConfigHome = $this->environmentValue('XDG_CONFIG_HOME');
        if ($xdgConfigHome !== null) {
            return $this->buildPath($xdgConfigHome, 'drupalorg-cli');
        }

        if ($this->operatingSystemFamily() === 'Windows') {
            $appData = $this->environmentValue('APPDATA');
            if ($appData !== null) {
                return $this->buildPath($appData, 'drupalorg-cli');
            }
        }

        $home = $this->environmentValue('HOME');
        if ($home !== null) {
            return $this->buildPath($home, '.config', 'drupalorg-cli');
        }

        throw new \RuntimeException('Could not determine the configuration directory.');
    }

    private function operatingSystemFamily(): string
    {
        return $this->osFamily ?? PHP_OS_FAMILY;
    }

    private function environmentValue(string $name): ?string
    {
        $value = $this->environment === null ? getenv($name) : ($this->environment[$name] ?? null);
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function buildPath(string $baseDirectory, string ...$segments): string
    {
        $separator = $this->operatingSystemFamily() === 'Windows' ? '\\' : DIRECTORY_SEPARATOR;
        $baseDirectory = rtrim($baseDirectory, '/\\');
        if ($baseDirectory === '') {
            return $separator . implode($separator, $segments);
        }

        return implode($separator, [$baseDirectory, ...$segments]);
    }
}
