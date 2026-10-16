<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use Composer\Semver\Semver;
use mglaman\DrupalOrg\Client;

final class DrupalOrgProjectCompatibilityResolver
{
    private const METADATA_ENDPOINT = 'https://packages.drupal.org/files/packages/8/p2/drupal/';

    /**
     * @param array{runtimeVersion: ?string, targetMajor: ?int} $targetCore
     *
     * @return array{
     *     status: 'compatible'|'outdated'|'requires_newer_core'|'unknown',
     *     targetCoreVersion: ?string,
     *     targetCoreMajor: ?int,
     *     supportedCoreMajors: list<int>,
     *     latestRelevantRelease: ?string,
     *     declaredCompatibility: list<string>
     * }
     */
    public function resolve(string $machineName, array $targetCore): array
    {
        $unknown = $this->unknown($targetCore);
        if (($targetCore['targetMajor'] ?? null) === null || trim($machineName) === '') {
            return $unknown;
        }

        try {
            $response = $this->client->getGuzzleClient()->request(
                'GET',
                self::METADATA_ENDPOINT . rawurlencode(trim($machineName)) . '.json',
                ['headers' => ['Accept' => 'application/json']],
            );
            $payload = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $unknown;
        }

        $packages = $payload['packages']['drupal/' . trim($machineName)] ?? null;
        if (!is_array($packages)) {
            return $unknown;
        }

        $supportedCoreMajors = [];
        $declaredCompatibility = [];
        $latestRelease = null;
        foreach ($packages as $package) {
            if (!is_array($package) || !$this->isStableVersion($package['version'] ?? null)) {
                continue;
            }

            $requirements = $package['require'] ?? null;
            if (!is_array($requirements)) {
                continue;
            }
            $constraint = $requirements['drupal/core'] ?? $requirements['drupal/core-recommended'] ?? null;
            if (!is_string($constraint) || trim($constraint) === '') {
                continue;
            }

            $constraint = trim($constraint);
            $declaredCompatibility[] = $constraint;
            $version = (string) $package['version'];
            if ($latestRelease === null || version_compare($version, $latestRelease, '>')) {
                $latestRelease = $version;
            }
            for ($major = 1; $major <= 99; ++$major) {
                if ($this->constraintSupportsMajor($constraint, $major)) {
                    $supportedCoreMajors[$major] = true;
                }
            }
        }

        $supportedCoreMajors = array_map('intval', array_keys($supportedCoreMajors));
        sort($supportedCoreMajors);
        $declaredCompatibility = array_values(array_unique($declaredCompatibility));
        if ($supportedCoreMajors === []) {
            return $unknown;
        }

        $targetMajor = $targetCore['targetMajor'];
        $status = 'unknown';
        if (in_array($targetMajor, $supportedCoreMajors, true)) {
            $status = 'compatible';
        } elseif (max($supportedCoreMajors) < $targetMajor) {
            $status = 'outdated';
        } elseif (min($supportedCoreMajors) > $targetMajor) {
            $status = 'requires_newer_core';
        }

        return [
            'status' => $status,
            'targetCoreVersion' => $targetCore['runtimeVersion'],
            'targetCoreMajor' => $targetMajor,
            'supportedCoreMajors' => $supportedCoreMajors,
            'latestRelevantRelease' => $latestRelease,
            'declaredCompatibility' => $declaredCompatibility,
        ];
    }

    /**
     * @param array{runtimeVersion: ?string, targetMajor: ?int} $targetCore
     *
     * @return array{
     *     status: 'unknown',
     *     targetCoreVersion: ?string,
     *     targetCoreMajor: ?int,
     *     supportedCoreMajors: list<int>,
     *     latestRelevantRelease: null,
     *     declaredCompatibility: list<string>
     * }
     */
    public function unknown(array $targetCore): array
    {
        return [
            'status' => 'unknown',
            'targetCoreVersion' => $targetCore['runtimeVersion'] ?? null,
            'targetCoreMajor' => $targetCore['targetMajor'] ?? null,
            'supportedCoreMajors' => [],
            'latestRelevantRelease' => null,
            'declaredCompatibility' => [],
        ];
    }

    public function __construct(
        private readonly Client $client,
    ) {
    }

    private function isStableVersion(mixed $version): bool
    {
        return is_string($version)
            && trim($version) !== ''
            && preg_match('/(?:dev|alpha|beta|rc)/i', $version) !== 1;
    }

    private function constraintSupportsMajor(string $constraint, int $major): bool
    {
        try {
            for ($minor = 0; $minor <= 99; ++$minor) {
                if (Semver::satisfies(sprintf('%d.%d.0', $major, $minor), $constraint)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
