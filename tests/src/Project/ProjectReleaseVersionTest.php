<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectReleaseVersion::class)]
final class ProjectReleaseVersionTest extends TestCase
{
    #[DataProvider('validVersions')]
    public function testParsesValidVersion(string $value, bool $stable, string $branch): void
    {
        $version = ProjectReleaseVersion::fromString($value);
        self::assertSame($value, $version->value);
        self::assertSame($stable, $version->isStable());
        self::assertSame($branch, $version->suggestedBranch()->name);
    }

    /** @return array<string, array{string, bool, string}> */
    public static function validVersions(): array
    {
        return [
            'stable' => ['1.0.0', true, '1.x'],
            'patch' => ['1.2.3', true, '1.x'],
            'alpha' => ['1.0.0-alpha1', false, '1.x'],
            'beta' => ['1.0.0-beta2', false, '1.x'],
            'rc' => ['2.0.0-rc3', false, '2.x'],
        ];
    }

    #[DataProvider('invalidVersions')]
    public function testRejectsInvalidVersion(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProjectReleaseVersion::fromString($value);
    }

    /** @return array<string, array{string}> */
    public static function invalidVersions(): array
    {
        return ['v prefix' => ['v1.0.0'], 'short' => ['1.0'], 'suffix' => ['1.0.0-beta'], 'branch' => ['1.x'], 'legacy' => ['9.x-1.0']];
    }
}
