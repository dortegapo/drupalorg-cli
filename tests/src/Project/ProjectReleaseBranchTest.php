<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectReleaseBranch::class)]
final class ProjectReleaseBranchTest extends TestCase
{
    #[DataProvider('validBranches')]
    public function testAcceptsReleaseBranches(string $branch): void
    {
        self::assertSame($branch, ProjectReleaseBranch::fromString($branch)->name);
    }

    /** @return array<string, array{string}> */
    public static function validBranches(): array
    {
        return ['major' => ['1.x'], 'minor' => ['1.2.x']];
    }

    #[DataProvider('invalidBranches')]
    public function testRejectsNonReleaseBranches(string $branch): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProjectReleaseBranch::fromString($branch);
    }

    /** @return array<string, array{string}> */
    public static function invalidBranches(): array
    {
        return ['main' => ['main'], 'master' => ['master'], 'feature' => ['feature/foo'], 'tag' => ['1.0.0']];
    }
}
