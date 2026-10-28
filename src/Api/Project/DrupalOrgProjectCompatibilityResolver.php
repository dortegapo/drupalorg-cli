<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use Composer\MetadataMinifier\MetadataMinifier;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use mglaman\DrupalOrg\Client;
use Psr\Http\Message\ResponseInterface;

final class DrupalOrgProjectCompatibilityResolver
{
    private const METADATA_ENDPOINT = 'https://packages.drupal.org/files/packages/8/p2/drupal/';

    private const LOOKUP_CONCURRENCY = 4;

    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * Resolve one Drupal.org project against the local module's supported core majors.
     *
     * @param list<int> $localCoreMajors
     *
     * @return array{
     *     status: 'compatible'|'incompatible'|'unknown',
     *     supportedCoreMajors: list<int>,
     *     latestRelevantRelease: ?string,
     *     declaredCompatibility: list<string>
     * }
     */
    public function resolve(string $machineName, array $localCoreMajors): array
    {
        $unknown = $this->unknown();
        if (trim($machineName) === '' || $localCoreMajors === []) {
            return $unknown;
        }

        try {
            $response = $this->client->getGuzzleClient()->request(
                'GET',
                $this->metadataUrl($machineName),
                $this->requestOptions(),
            );

            return $this->resolvePayload($response, $localCoreMajors);
        } catch (\Throwable) {
            return $unknown;
        }
    }

    /**
     * Resolve a bounded batch concurrently. Failed lookups remain unknown.
     *
     * @param list<string> $machineNames
     * @param list<int> $localCoreMajors
     *
     * @return array<string, array{
     *     status: 'compatible'|'incompatible'|'unknown',
     *     supportedCoreMajors: list<int>,
     *     latestRelevantRelease: ?string,
     *     declaredCompatibility: list<string>
     * }>
     */
    public function resolveMany(
        array $machineNames,
        array $localCoreMajors,
        int $concurrency = self::LOOKUP_CONCURRENCY,
    ): array {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException('Compatibility lookup concurrency must be greater than zero.');
        }

        $machineNames = array_values(array_unique(array_filter(
            $machineNames,
            static fn (string $machineName): bool => trim($machineName) !== '',
        )));
        if ($machineNames === [] || $localCoreMajors === []) {
            return [];
        }

        $resolved = [];
        foreach (array_chunk($machineNames, $concurrency) as $batch) {
            /** @var array<string, PromiseInterface> $promises */
            $promises = [];
            foreach ($batch as $machineName) {
                $promises[$machineName] = $this->requestMetadataAsync($machineName);
            }

            $settled = Utils::settle($promises)->wait();
            foreach ($batch as $machineName) {
                $result = $settled[$machineName] ?? null;
                if (!is_array($result) || ($result['state'] ?? null) !== 'fulfilled') {
                    $resolved[$machineName] = $this->unknown();
                    continue;
                }

                try {
                    $response = $result['value'];
                    if (!$response instanceof ResponseInterface) {
                        throw new \RuntimeException('Drupal.org compatibility metadata response was invalid.');
                    }
                    $resolved[$machineName] = $this->resolvePayload($response, $localCoreMajors);
                } catch (\Throwable) {
                    $resolved[$machineName] = $this->unknown();
                }
            }
        }

        return $resolved;
    }

    /**
     * @return array{
     *     status: 'unknown',
     *     supportedCoreMajors: list<int>,
     *     latestRelevantRelease: ?string,
     *     declaredCompatibility: list<string>
     * }
     */
    public function unknown(): array
    {
        return [
            'status' => 'unknown',
            'supportedCoreMajors' => [],
            'latestRelevantRelease' => null,
            'declaredCompatibility' => [],
        ];
    }

    /**
     * @param list<int> $localCoreMajors
     *
     * @return array{
     *     status: 'compatible'|'incompatible'|'unknown',
     *     supportedCoreMajors: list<int>,
     *     latestRelevantRelease: ?string,
     *     declaredCompatibility: list<string>
     * }
     */
    private function resolvePayload(ResponseInterface $response, array $localCoreMajors): array
    {
        $payload = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            return $this->unknown();
        }

        return $this->resolvePackages($payload, $localCoreMajors);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<int> $localCoreMajors
     *
     * @return array{
     *     status: 'compatible'|'incompatible'|'unknown',
     *     supportedCoreMajors: list<int>,
     *     latestRelevantRelease: ?string,
     *     declaredCompatibility: list<string>
     * }
     */
    private function resolvePackages(array $payload, array $localCoreMajors): array
    {
        $packagesByProject = $payload['packages'] ?? null;
        if (!is_array($packagesByProject) || count($packagesByProject) !== 1) {
            return $this->unknown();
        }
        $packages = reset($packagesByProject);
        if (!is_array($packages)) {
            return $this->unknown();
        }
        if (($payload['minified'] ?? null) === 'composer/2.0') {
            $packages = MetadataMinifier::expand($packages);
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
            try {
                foreach (DrupalCoreVersionRequirement::supportedMajors($constraint) as $major) {
                    $supportedCoreMajors[$major] = true;
                }
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        $supportedCoreMajors = array_map('intval', array_keys($supportedCoreMajors));
        sort($supportedCoreMajors);
        $declaredCompatibility = array_values(array_unique($declaredCompatibility));
        if ($supportedCoreMajors === []) {
            return $this->unknown();
        }

        return [
            'status' => array_intersect($localCoreMajors, $supportedCoreMajors) !== []
                ? 'compatible'
                : 'incompatible',
            'supportedCoreMajors' => $supportedCoreMajors,
            'latestRelevantRelease' => $latestRelease,
            'declaredCompatibility' => $declaredCompatibility,
        ];
    }

    private function requestMetadataAsync(string $machineName): PromiseInterface
    {
        return $this->client->getGuzzleClient()->requestAsync(
            'GET',
            $this->metadataUrl($machineName),
            $this->requestOptions(),
        );
    }

    /** @return array<string, mixed> */
    private function requestOptions(): array
    {
        return [
            'connect_timeout' => 5.0,
            'timeout' => 10.0,
            'headers' => ['Accept' => 'application/json'],
        ];
    }

    private function metadataUrl(string $machineName): string
    {
        return self::METADATA_ENDPOINT . rawurlencode(trim($machineName)) . '.json';
    }

    private function isStableVersion(mixed $version): bool
    {
        return is_string($version)
            && trim($version) !== ''
            && preg_match('/(?:dev|alpha|beta|rc)/i', $version) !== 1;
    }
}
