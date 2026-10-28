<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Project\DrupalOrgProjectCompatibilityResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DrupalOrgProjectCompatibilityResolver::class)]
final class DrupalOrgProjectCompatibilityResolverTest extends TestCase
{
    public function testReportsCompatibleWhenAStableReleaseSupportsALocalMajor(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([
                self::package('2.0.1', '^10.2 || ^11.0'),
                self::package('1.4.0', '^9.2 || ^10'),
            ]),
        ]);

        $result = $resolver->resolve('coffee', [11]);

        self::assertSame('compatible', $result['status']);
        self::assertSame([9, 10, 11], $result['supportedCoreMajors']);
        self::assertSame('^10.2 || ^11.0', $result['declaredCompatibility'][0]);
    }

    public function testReportsIncompatibleWhenThereIsNoIntersection(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([self::package('1.4.0', '^9.2 || ^10')]),
        ]);

        self::assertSame('incompatible', $resolver->resolve('coffee', [11])['status']);
    }

    public function testCompatibilityUsesAnyLocalMajorIntersection(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([self::package('1.0.0', '^10')]),
            self::metadataResponse([self::package('1.0.0', '^11')]),
            self::metadataResponse([self::package('1.0.0', '^12')]),
        ]);

        self::assertSame('compatible', $resolver->resolve('first', [10, 11])['status']);
        self::assertSame('compatible', $resolver->resolve('second', [10, 11])['status']);
        self::assertSame('incompatible', $resolver->resolve('third', [10, 11])['status']);
    }

    public function testSupportsPatchSpecificAndRangeConstraints(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([self::package('2.0.1', '~11.4.1')]),
            self::metadataResponse([self::package('2.0.1', '>=11.4.1 <12')]),
        ]);

        self::assertSame('compatible', $resolver->resolve('coffee', [11])['status']);
        self::assertSame('compatible', $resolver->resolve('coffee', [11])['status']);
    }

    public function testExpandsMinifiedMetadataBeforeCheckingCompatibility(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([
                self::package('2.0.1', '^10'),
                ['version' => '2.0.2'],
            ], true),
        ]);

        $result = $resolver->resolve('coffee', [11]);

        self::assertSame('incompatible', $result['status']);
        self::assertSame(['^10'], $result['declaredCompatibility']);
    }

    public function testReportsUnknownWhenMetadataIsMissingOrRequestFails(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([['version' => '2.0.1']]),
            new Response(500),
        ]);

        self::assertSame('unknown', $resolver->resolve('coffee', [11])['status']);
        self::assertSame('unknown', $resolver->resolve('coffee', [11])['status']);
    }

    public function testResolvesManyCandidatesConcurrentlyAndKeepsFailuresUnknown(): void
    {
        $resolver = $this->resolver([
            self::metadataResponse([self::package('1.0.0', '^11')]),
            self::metadataResponse([self::package('1.0.0', '^10')]),
            new Response(500),
        ]);

        $result = $resolver->resolveMany(['compatible', 'incompatible', 'failed'], [11], 2);

        self::assertSame('compatible', $result['compatible']['status']);
        self::assertSame('incompatible', $result['incompatible']['status']);
        self::assertSame('unknown', $result['failed']['status']);
    }

    public function testUsesInteractiveTimeoutsForMetadataLookup(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            self::metadataResponse([self::package('2.0.1', '^11')]),
        ]));
        $stack->push(Middleware::history($history));
        $resolver = new DrupalOrgProjectCompatibilityResolver(new Client(false, $stack));

        $resolver->resolve('coffee', [11]);

        self::assertSame(5.0, $history[0]['options']['connect_timeout']);
        self::assertSame(10.0, $history[0]['options']['timeout']);
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
    private static function metadataResponse(array $packages, bool $minified = false): Response
    {
        $payload = [
            'packages' => ['drupal/coffee' => $packages],
        ];
        if ($minified) {
            $payload['minified'] = 'composer/2.0';
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
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
}
