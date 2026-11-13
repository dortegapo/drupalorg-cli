<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ModuleReviewSourceSelector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleReviewSourceSelector::class)]
final class ModuleReviewSourceSelectorTest extends TestCase
{
    public function testKeepsAllFilesInFullMode(): void
    {
        $files = [self::file('a.php', 10), self::file('b.php', 20)];

        $result = (new ModuleReviewSourceSelector())->select($files);

        self::assertSame($files, $result['files']);
        self::assertSame([], $result['omitted']);
        self::assertSame('full', $result['coverage']['mode']);
        self::assertNull($result['coverage']['requestedBudgetBytes']);
    }

    public function testPrioritizesModuleMetadataOverStyles(): void
    {
        $result = (new ModuleReviewSourceSelector())->select([
            self::file('css/module.css', 90),
            self::file('example.info.yml', 90),
        ], 90);

        self::assertSame(['example.info.yml'], array_column($result['files'], 'path'));
        self::assertSame(['styles'], array_column($result['omitted'], 'category'));
        self::assertLessThanOrEqual(90, $result['selectedBytes']);
    }

    public function testReportsConfigurationAsItsOwnOmittedCategory(): void
    {
        $result = (new ModuleReviewSourceSelector())->select([
            self::file('config/schema/example.schema.yml', 90),
            self::file('css/module.css', 10),
        ], 10);

        self::assertSame(['configuration' => 1], $result['coverage']['omittedBreakdown']);
    }

    public function testPrioritizesRuntimePhpOverTests(): void
    {
        $result = (new ModuleReviewSourceSelector())->select([
            self::file('tests/src/Functional/ExampleTest.php', 90),
            self::file('src/Example.php', 90),
        ], 90);

        self::assertSame(['src/Example.php'], array_column($result['files'], 'path'));
    }

    public function testPrioritizesFunctionalJavascriptOverStyles(): void
    {
        $result = (new ModuleReviewSourceSelector())->select([
            self::file('css/module.css', 90),
            self::file('js/module.js', 90),
        ], 90);

        self::assertSame(['js/module.js'], array_column($result['files'], 'path'));
    }

    public function testUsesDiverseTestGroupsBeforeRepeatingAGroup(): void
    {
        $result = (new ModuleReviewSourceSelector())->select([
            self::file('tests/src/Functional/TokenNodeTest.php', 40),
            self::file('tests/src/Functional/TokenNodeAccessTest.php', 40),
            self::file('tests/src/Functional/TokenUserTest.php', 40),
        ], 80);

        $paths = array_column($result['files'], 'path');
        self::assertCount(2, $paths);
        self::assertNotContains('tests/src/Functional/TokenNodeTest.php', $paths);
        self::assertContains('tests/src/Functional/TokenUserTest.php', $paths);
    }

    public function testFunctionalAndKernelTestsBeatFixturesAndHelpers(): void
    {
        $result = (new ModuleReviewSourceSelector())->select([
            self::file('tests/fixtures/ExampleFixture.php', 40),
            self::file('tests/src/Functional/ExampleTest.php', 40),
            self::file('tests/src/Kernel/ExampleTest.php', 40),
        ], 80);

        self::assertSame(
            ['tests/src/Functional/ExampleTest.php', 'tests/src/Kernel/ExampleTest.php'],
            array_column($result['files'], 'path'),
        );
    }

    public function testNeverSplitsFilesAndIsDeterministic(): void
    {
        $files = [
            self::file('src/Large.php', 70),
            self::file('src/Small.php', 30),
            self::file('README.md', 20),
        ];
        $selector = new ModuleReviewSourceSelector();

        $first = $selector->select($files, 50);
        $second = $selector->select($files, 50);

        self::assertSame($first, $second);
        self::assertSame(['src/Small.php', 'README.md'], array_column($first['files'], 'path'));
        self::assertSame(50, $first['selectedBytes']);
        self::assertSame(70, $first['omittedBytes']);
        self::assertSame(2, $first['coverage']['selectedFiles']);
        self::assertSame(1, $first['coverage']['omittedFiles']);
    }

    public function testReportsPartialCoverageAndOmittedBreakdown(): void
    {
        $result = (new ModuleReviewSourceSelector())->select([
            self::file('example.info.yml', 60),
            self::file('css/module.css', 60),
        ], 60);

        self::assertSame('selected', $result['coverage']['mode']);
        self::assertSame(60, $result['coverage']['requestedBudgetBytes']);
        self::assertSame(['styles' => 1], $result['coverage']['omittedBreakdown']);
    }

    #[DataProvider('budgetBoundaries')]
    public function testBudgetBoundariesKeepTheExpectedFullOrSelectedMode(int $budget): void
    {
        $selector = new ModuleReviewSourceSelector();

        $below = $selector->select([self::file('src/Runtime.php', $budget - 1)], $budget);
        self::assertSame('full', $below['coverage']['mode']);

        $exact = $selector->select([self::file('src/Runtime.php', $budget)], $budget);
        self::assertSame('full', $exact['coverage']['mode']);

        $above = $selector->select([
            self::file('src/Runtime.php', $budget),
            self::file('README.md', 1),
        ], $budget);
        self::assertSame('selected', $above['coverage']['mode']);
        self::assertSame($budget, $above['selectedBytes']);
        self::assertSame(1, $above['omittedBytes']);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function budgetBoundaries(): array
    {
        return [
            '128 KiB' => [128 * 1024],
            '256 KiB' => [256 * 1024],
            '512 KiB' => [512 * 1024],
        ];
    }

    /**
     * @return array{path: string, bytes: int, content: string}
     */
    private static function file(string $path, int $bytes): array
    {
        return [
            'path' => $path,
            'bytes' => $bytes,
            'content' => str_repeat('x', $bytes),
        ];
    }
}
