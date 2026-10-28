<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\DrupalCoreVersionRequirement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DrupalCoreVersionRequirement::class)]
final class DrupalCoreVersionRequirementTest extends TestCase
{
    /**
     * @dataProvider requirementProvider
     *
     * @param list<int> $expected
     */
    public function testResolvesSupportedCoreMajors(string $constraint, array $expected): void
    {
        self::assertSame($expected, DrupalCoreVersionRequirement::supportedMajors($constraint));
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function requirementProvider(): iterable
    {
        yield 'major caret' => ['^11', [11]];
        yield 'two majors' => ['^10 || ^11', [10, 11]];
        yield 'minor caret' => ['^10.3 || ^11', [10, 11]];
        yield 'bounded range' => ['>=10.2 <12', [10, 11]];
        yield 'tilde' => ['~11.4.1', [11]];
        yield 'future majors' => ['^11 || ^12', [11, 12]];
    }
}
