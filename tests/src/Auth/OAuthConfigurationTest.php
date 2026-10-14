<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Auth;

use mglaman\DrupalOrg\Auth\OAuthConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OAuthConfiguration::class)]
class OAuthConfigurationTest extends TestCase
{
    public function testAcceptsAValidConfiguration(): void
    {
        $configuration = new OAuthConfiguration(
            'https://accounts.example.test/oauth/device',
            'https://accounts.example.test/oauth/token',
            'drupalorg-cli',
            ['project:create', 'profile'],
        );

        self::assertSame('https://accounts.example.test/oauth/device', $configuration->deviceAuthorizationEndpoint);
        self::assertSame('https://accounts.example.test/oauth/token', $configuration->tokenEndpoint);
        self::assertSame('drupalorg-cli', $configuration->clientId);
        self::assertSame(['project:create', 'profile'], $configuration->scopes);
    }

    /**
     * @param array{0: string, 1: string, 2: string} $configuration
     */
    #[DataProvider('invalidRequiredStringProvider')]
    public function testRejectsEmptyRequiredStrings(array $configuration): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OAuthConfiguration($configuration[0], $configuration[1], $configuration[2], []);
    }

    /**
     * @return iterable<string, array{0: array{0: string, 1: string, 2: string}}>
     */
    public static function invalidRequiredStringProvider(): iterable
    {
        yield 'device authorization endpoint' => [['', 'https://accounts.example.test/oauth/token', 'drupalorg-cli']];
        yield 'token endpoint' => [['https://accounts.example.test/oauth/device', '', 'drupalorg-cli']];
        yield 'client ID' => [['https://accounts.example.test/oauth/device', 'https://accounts.example.test/oauth/token', '']];
    }

    /**
     * @param array<mixed> $scopes
     */
    #[DataProvider('invalidScopeProvider')]
    public function testRejectsInvalidScopes(array $scopes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OAuthConfiguration(
            'https://accounts.example.test/oauth/device',
            'https://accounts.example.test/oauth/token',
            'drupalorg-cli',
            $scopes,
        );
    }

    /**
     * @return iterable<string, array{0: array<mixed>}>
     */
    public static function invalidScopeProvider(): iterable
    {
        yield 'empty scope' => [['']];
        yield 'non-string scope' => [[123]];
        yield 'associative scopes' => [['project:create' => 'project:create']];
    }
}
