<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Project\DrupalOrgProjectCompatibilityResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DrupalOrgProjectCompatibilityResolver::class)]
final class DrupalOrgProjectCompatibilityResolverTest extends TestCase
{
    public function testReportsCompatibleWhenAStableReleaseSupportsTheTargetMajor(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([
                self::package('2.0.1', '^10.2 || ^11.0'),
                self::package('1.4.0', '^9.2 || ^10'),
            ]),
        ]);

        $result = $resolver->resolve('coffee', self::targetCore());

        self::assertSame('compatible', $result['status']);
        self::assertSame([9, 10, 11], $result['supportedCoreMajors']);
        self::assertSame('2.0.1', $result['latestRelevantRelease']);
        self::assertSame('^10.2 || ^11.0', $result['declaredCompatibility'][0]);
    }

    public function testReportsOutdatedWhenOnlyLowerCoreMajorsAreDeclared(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([
                self::package('1.4.0', '^9.2 || ^10'),
            ]),
        ]);

        self::assertSame('outdated', $resolver->resolve('coffee', self::targetCore())['status']);
    }

    public function testReportsRequiresNewerCoreWhenOnlyHigherMajorsAreDeclared(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([
                self::package('3.0.0', '^12'),
            ]),
        ]);

        self::assertSame('requires_newer_core', $resolver->resolve('coffee', self::targetCore())['status']);
    }

    public function testReportsUnknownWhenCompatibilityMetadataIsMissing(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([
                ['version' => '2.0.1'],
            ]),
        ]);

        self::assertSame('unknown', $resolver->resolve('coffee', self::targetCore())['status']);
    }

    public function testReportsUnknownWhenThePublicMetadataLookupFails(): void
    {
        $resolver = $this->resolver([new Response(500)]);

        self::assertSame('unknown', $resolver->resolve('coffee', self::targetCore())['status']);
    }

    public function testDoesNotTreatDevelopmentReleasesAsStableCompatibilityEvidence(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([
                self::package('3.0.0-alpha1', '^11'),
                self::package('2.0.1', '^10'),
            ]),
        ]);

        self::assertSame('outdated', $resolver->resolve('coffee', self::targetCore())['status']);
    }

    public function testUnknownTargetDoesNotMakeARequest(): void
    {
        $resolver = $this->resolver([]);

        $result = $resolver->resolve('coffee', [
            'runtimeVersion' => null,
            'targetMajor' => null,
        ]);

        self::assertSame('unknown', $result['status']);
    }

    /**
     * @param list<Response> $responses
     */
    private function resolver(array $responses): DrupalOrgProjectCompatibilityResolver
    {
        return new DrupalOrgProjectCompatibilityResolver(
            new Client(false, HandlerStack::create(new MockHandler($responses))),
        );
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private static function metadataResponse(array $packages): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['packages' => ['drupal/coffee' => $packages], 'minified' => 'composer/2.0'], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{version: string, require: array{'drupal/core': string}}
     */
    private static function package(string $version, string $constraint): array
    {
        return [
            'version' => $version,
            'require' => ['drupal/core' => $constraint],
        ];
    }

    /**
     * @return array{runtimeVersion: string, targetMajor: int}
     */
    private static function targetCore(): array
    {
        return [
            'runtimeVersion' => '11.4.8',
            'targetMajor' => 11,
        ];
    }
}
