<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ModuleReviewCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleReviewCollector::class)]
class ModuleReviewCollectorTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/drupalorg-cli-review-collector-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDir);
    }

    public function testCollectsSupportedFilesWithRelativePathsAndExactContent(): void
    {
        $this->writeFile('my_module.info.yml', "name: My Module\n");
        $this->writeFile('README.md', "# My Module\n");
        $this->writeFile('src/Service/Foo.php', "<?php\n\nfinal class Foo {}\n");

        $result = $this->collect();

        self::assertSame([
            'README.md',
            'my_module.info.yml',
            'src/Service/Foo.php',
        ], array_column($result['files'], 'path'));
        self::assertSame("# My Module\n", $result['files'][0]['content']);
        self::assertSame("name: My Module\n", $result['files'][1]['content']);
        self::assertSame("<?php\n\nfinal class Foo {}\n", $result['files'][2]['content']);
    }

    public function testSortsFilesDeterministically(): void
    {
        $this->writeFile('z.php', 'z');
        $this->writeFile('a.php', 'a');
        $this->writeFile('src/Middle.php', 'middle');

        $result = $this->collect();

        self::assertSame(['a.php', 'src/Middle.php', 'z.php'], array_column($result['files'], 'path'));
    }

    public function testReadmeWithoutExtensionOrWithTextExtensionIsSupported(): void
    {
        $this->writeFile('README', 'readme');
        $this->writeFile('README.md', 'markdown');
        $this->writeFile('README.txt', 'text');
        $this->writeFile('README.pdf', 'pdf');
        $this->writeFile('README.png', 'png');

        $result = $this->collect();

        self::assertSame(['README', 'README.md', 'README.txt'], array_column($result['files'], 'path'));
        self::assertSame([
            'README.pdf' => 'unsupported',
            'README.png' => 'unsupported',
        ], $this->skippedByPath($result));
    }

    public function testExcludesDependencyAndGeneratedDirectories(): void
    {
        foreach (['.git', 'vendor', 'node_modules', 'coverage', 'dist', 'build', '.idea', '.vscode'] as $directory) {
            $this->writeFile($directory . '/ignored.php', 'ignored');
        }
        $this->writeFile('src/Foo.php', 'included');

        $result = $this->collect();

        self::assertSame(['src/Foo.php'], array_column($result['files'], 'path'));
    }

    public function testExcludesSensitiveFilesButIncludesRelevantAuthCode(): void
    {
        $this->writeFile('.env', 'secret');
        $this->writeFile('.env.local', 'secret');
        $this->writeFile('credentials.json', '{}');
        $this->writeFile('private.pem', 'private');
        $this->writeFile('src/TokenManager.php', '<?php');

        $result = $this->collect();

        self::assertSame(['src/TokenManager.php'], array_column($result['files'], 'path'));
        self::assertSame([
            '.env' => 'sensitive',
            '.env.local' => 'sensitive',
            'credentials.json' => 'sensitive',
            'private.pem' => 'sensitive',
        ], $this->skippedByPath($result));
    }

    public function testSkipsSymlinksWithoutReadingTheirTargets(): void
    {
        $externalFile = $this->workDir . '/external.txt';
        file_put_contents($externalFile, 'external secret');
        $link = $this->workDir . '/module/external.txt';
        mkdir(dirname($link), 0755, true);

        if (!symlink($externalFile, $link)) {
            self::markTestSkipped('The test environment does not allow symlinks.');
        }

        $result = $this->collect($this->workDir . '/module');

        self::assertSame([
            'external.txt' => 'symlink',
        ], $this->skippedByPath($result));
        self::assertNotContains('external secret', array_column($result['files'], 'content'));
    }

    public function testRecordsUnreadableSubdirectoriesWithoutEmittingWarnings(): void
    {
        $unreadableDirectory = $this->workDir . '/unreadable';
        mkdir($unreadableDirectory);
        if (!chmod($unreadableDirectory, 0000) || is_readable($unreadableDirectory)) {
            chmod($unreadableDirectory, 0755);
            self::markTestSkipped('The test environment does not allow unreadable directories.');
        }

        try {
            $result = $this->collect();
        } finally {
            chmod($unreadableDirectory, 0755);
        }

        self::assertSame(['unreadable' => 'unreadable'], $this->skippedByPath($result));
    }

    public function testExcludesLockfiles(): void
    {
        $this->writeFile('composer.json', '{}');
        $this->writeFile('composer.lock', '{}');
        $this->writeFile('package-lock.json', '{}');
        $this->writeFile('pnpm-lock.yaml', 'lockfile');
        $this->writeFile('yarn.lock', 'lockfile');

        $result = $this->collect();

        self::assertSame(['composer.json'], array_column($result['files'], 'path'));
        self::assertSame([
            'composer.lock' => 'lockfile',
            'package-lock.json' => 'lockfile',
            'pnpm-lock.yaml' => 'lockfile',
            'yarn.lock' => 'lockfile',
        ], $this->skippedByPath($result));
    }

    public function testExcludesMinifiedAssets(): void
    {
        $this->writeFile('js/app.js', 'app');
        $this->writeFile('js/app.min.js', 'minified');
        $this->writeFile('css/app.css', 'css');
        $this->writeFile('css/app.min.css', 'minified');

        $result = $this->collect();

        self::assertSame(['css/app.css', 'js/app.js'], array_column($result['files'], 'path'));
        self::assertSame([
            'css/app.min.css' => 'minified',
            'js/app.min.js' => 'minified',
        ], $this->skippedByPath($result));
    }

    public function testSkipsFilesLargerThanMaximumSize(): void
    {
        $this->writeFile('large.txt', str_repeat('a', 262145));

        $result = $this->collect();

        self::assertSame([], $result['files']);
        self::assertSame(['large.txt' => 'too_large'], $this->skippedByPath($result));
    }

    public function testSkipsBinaryFilesWithSupportedExtensions(): void
    {
        $this->writeFile('fake.php', "foo\0bar");

        $result = $this->collect();

        self::assertSame([], $result['files']);
        self::assertSame(['fake.php' => 'binary'], $this->skippedByPath($result));
    }

    public function testSkipsNonUtf8FilesWithSupportedExtensions(): void
    {
        $this->writeFile('legacy.php', "<?php\n\xff");

        $result = $this->collect();

        self::assertSame([], $result['files']);
        self::assertSame(['legacy.php' => 'non_utf8'], $this->skippedByPath($result));
    }

    public function testRecordsUnsupportedFilesWithoutReadingThem(): void
    {
        $this->writeFile('logo.png', 'not read');
        $this->writeFile('archive.zip', 'not read');

        $result = $this->collect();

        self::assertSame([], $result['files']);
        self::assertSame([
            'archive.zip' => 'unsupported',
            'logo.png' => 'unsupported',
        ], $this->skippedByPath($result));
    }

    public function testTotalBytesIncludesOnlyCollectedFiles(): void
    {
        $this->writeFile('a.txt', '123');
        $this->writeFile('b.txt', '12345');
        $this->writeFile('large.txt', str_repeat('a', 262145));

        $result = $this->collect();

        self::assertSame(8, $result['totalBytes']);
        self::assertSame(
            array_sum(array_map(static fn (array $file): int => strlen($file['content']), $result['files'])),
            $result['totalBytes']
        );
    }

    /**
     * @return array{
     *     files: list<array{path: string, bytes: int, content: string}>,
     *     skipped: list<array{path: string, reason: string}>,
     *     totalBytes: int
     * }
     */
    private function collect(?string $modulePath = null): array
    {
        return (new ModuleReviewCollector())->collect($modulePath ?? $this->workDir);
    }

    private function writeFile(string $relativePath, string $content): void
    {
        $path = $this->workDir . '/' . $relativePath;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * @param array{
     *     files: list<array{path: string, bytes: int, content: string}>,
     *     skipped: list<array{path: string, reason: string}>,
     *     totalBytes: int
     * } $result
     *
     * @return array<string, string>
     */
    private function skippedByPath(array $result): array
    {
        $skipped = [];
        foreach ($result['skipped'] as $item) {
            $skipped[$item['path']] = $item['reason'];
        }

        return $skipped;
    }

    private static function removeDirectory(string $directory): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
