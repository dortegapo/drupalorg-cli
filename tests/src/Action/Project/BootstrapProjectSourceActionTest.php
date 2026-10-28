<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Action\Project;

use mglaman\DrupalOrg\Action\Project\BootstrapProjectSourceAction;
use mglaman\DrupalOrg\Action\Project\InspectLocalProjectSourceAction;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ModuleInfo;
use mglaman\DrupalOrg\Project\ResolvedModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(BootstrapProjectSourceAction::class)]
final class BootstrapProjectSourceActionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/drupalorg-bootstrap-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testNoRepositoryInitializesOnlyTheStandaloneModule(): void
    {
        $source = $this->createModule('foo');
        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));

        $result = (new BootstrapProjectSourceAction())($this->module($source, 'foo'), $inspection, 'Test User', 'test@example.test');

        self::assertTrue($result->repositoryExists);
        self::assertFalse($result->bootstrapRequired);
        self::assertSame(realpath($source), realpath($result->gitRoot));
        self::assertSame('Initial foo implementation', $this->git($source, ['log', '-1', '--pretty=%s']));
        self::assertSame(['foo.info.yml', 'foo.module'], $this->treeNames($source));
        self::assertFileExists($source . '/.git');
    }

    public function testUnbornRepositoryCreatesFirstModuleOnlyCommit(): void
    {
        $source = $this->createModule('foo');
        $this->git($source, ['init']);
        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));

        (new BootstrapProjectSourceAction())($this->module($source, 'foo'), $inspection, 'Test User', 'test@example.test');

        self::assertSame('Initial foo implementation', $this->git($source, ['log', '-1', '--pretty=%s']));
        self::assertSame(['foo.info.yml', 'foo.module'], $this->treeNames($source));
    }

    public function testExistingHistoryBootstrapsOnlyTheNewModule(): void
    {
        $root = $this->directory . '/project';
        $source = $root . '/web/modules/custom/foo';
        mkdir($source, 0700, true);
        $this->git($root, ['init']);
        $this->configureIdentity($root);
        file_put_contents($root . '/README.md', 'project');
        $this->git($root, ['add', '--', 'README.md']);
        $this->git($root, ['commit', '-m', 'Project']);
        file_put_contents($source . '/foo.info.yml', "name: Foo\ntype: module\ncore_version_requirement: ^11\n");
        file_put_contents($source . '/foo.module', "<?php\n");

        $module = $this->module($source, 'foo', $root, 'web');
        $inspection = (new InspectLocalProjectSourceAction())($module);
        $result = (new BootstrapProjectSourceAction())($module, $inspection);

        self::assertFalse($result->bootstrapRequired);
        self::assertSame(['README.md', 'web'], $this->treeNames($root));
        self::assertSame(
            ['foo.info.yml', 'foo.module'],
            $this->treeNames($root, 'HEAD:web/modules/custom/foo'),
        );
    }

    public function testExistingCleanModuleIsNotMutated(): void
    {
        $source = $this->createCommittedModule('foo');
        $before = $this->git($source, ['rev-parse', 'HEAD']);
        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));

        (new BootstrapProjectSourceAction())($this->module($source, 'foo'), $inspection);

        self::assertSame($before, $this->git($source, ['rev-parse', 'HEAD']));
    }

    public function testDirtyTrackedModuleIsRejected(): void
    {
        $source = $this->createCommittedModule('foo');
        file_put_contents($source . '/foo.module', "<?php\nchanged\n");
        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));

        $this->expectExceptionMessage('Module source contains uncommitted changes');
        (new BootstrapProjectSourceAction())($this->module($source, 'foo'), $inspection);
    }

    public function testStagedTrackedModuleIsRejected(): void
    {
        $source = $this->createCommittedModule('foo');
        file_put_contents($source . '/foo.module', "<?php\nstaged\n");
        $this->git($source, ['add', '--', 'foo.module']);
        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));

        self::assertFalse($inspection->moduleClean);
        $this->expectExceptionMessage('Module source contains uncommitted changes');
        (new BootstrapProjectSourceAction())($this->module($source, 'foo'), $inspection);
    }

    public function testUntrackedFileInsideCommittedModuleIsRejected(): void
    {
        $source = $this->createCommittedModule('foo');
        file_put_contents($source . '/new.php', "<?php\n");
        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));

        self::assertFalse($inspection->moduleClean);
        $this->expectExceptionMessage('Module source contains uncommitted changes');
        (new BootstrapProjectSourceAction())($this->module($source, 'foo'), $inspection);
    }

    public function testIgnoredFileInsideCommittedModuleDoesNotBlockPublication(): void
    {
        $source = $this->createCommittedModule('foo');
        file_put_contents($source . '/.gitignore', ".env\n");
        $this->git($source, ['add', '--', '.gitignore']);
        $this->git($source, ['commit', '-m', 'Ignore local environment']);
        file_put_contents($source . '/.env', 'ignored');

        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));

        self::assertTrue($inspection->moduleClean);
        self::assertFalse($inspection->bootstrapRequired);
    }

    public function testUnrelatedDirtyFileIsAllowed(): void
    {
        $root = $this->directory . '/project';
        $source = $this->createCommittedModule('foo', $root . '/web/modules/custom/foo', $root, 'web');
        file_put_contents($root . '/UNRELATED.txt', 'dirty');
        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo', $root, 'web'));

        self::assertTrue($inspection->moduleClean);
        self::assertFalse($inspection->bootstrapRequired);
    }

    public function testStagedUnrelatedFileBlocksBootstrapWithoutChangingTheIndex(): void
    {
        $root = $this->directory . '/project';
        $source = $root . '/web/modules/custom/foo';
        mkdir($source, 0700, true);
        $this->git($root, ['init']);
        $this->configureIdentity($root);
        file_put_contents($root . '/README.md', 'project');
        $this->git($root, ['add', '--', 'README.md']);
        $this->git($root, ['commit', '-m', 'Project']);
        file_put_contents($source . '/foo.info.yml', "name: Foo\ntype: module\ncore_version_requirement: ^11\n");
        file_put_contents($source . '/foo.module', "<?php\n");
        file_put_contents($root . '/UNRELATED.txt', 'staged');
        $this->git($root, ['add', '--', 'UNRELATED.txt']);
        $module = $this->module($source, 'foo', $root, 'web');
        $inspection = (new InspectLocalProjectSourceAction())($module);

        self::assertTrue($inspection->stagedChangesOutsideModule);
        try {
            (new BootstrapProjectSourceAction())($module, $inspection, 'Test User', 'test@example.test');
            self::fail('Expected staged unrelated change failure.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('staged changes outside the module', $exception->getMessage());
        }
        self::assertSame(['UNRELATED.txt'], explode("\n", $this->git($root, ['diff', '--cached', '--name-only'])));
    }

    public function testIgnoredFilesAreNotForcedIntoBootstrapCommit(): void
    {
        $source = $this->createModule('foo');
        file_put_contents($source . '/.gitignore', ".env\n");
        file_put_contents($source . '/.env', 'ignored');
        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));

        $result = (new BootstrapProjectSourceAction())($this->module($source, 'foo'), $inspection, 'Test User', 'test@example.test');

        self::assertFalse($result->bootstrapRequired);
        self::assertSame(['.gitignore', 'foo.info.yml', 'foo.module'], $this->treeNames($source));
    }

    public function testMissingIdentityFailsWithoutCreatingACommit(): void
    {
        $source = $this->createModule('foo');
        $this->git($source, ['init']);
        $this->git($source, ['config', '--local', 'user.name', '']);
        $this->git($source, ['config', '--local', 'user.email', '']);
        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));

        $this->expectExceptionMessage('Git identity is not configured');
        (new BootstrapProjectSourceAction())($this->module($source, 'foo'), $inspection);
    }

    public function testDetachedHeadAndExistingRemotesRemainUnchanged(): void
    {
        $source = $this->createCommittedModule('foo');
        $this->git($source, ['remote', 'add', 'origin', 'git@example.test:user/foo.git']);
        $this->git($source, ['remote', 'add', 'drupal', 'git@example.test:drupal/foo.git']);
        $this->git($source, ['tag', 'before']);
        $this->git($source, ['checkout', '--detach', 'HEAD']);
        $beforeRemotes = $this->git($source, ['remote', '-v']);
        $beforeTags = $this->git($source, ['tag']);
        $beforeHead = $this->git($source, ['rev-parse', 'HEAD']);
        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));

        (new BootstrapProjectSourceAction())($this->module($source, 'foo'), $inspection);

        self::assertSame($beforeHead, $this->git($source, ['rev-parse', 'HEAD']));
        self::assertSame($beforeRemotes, $this->git($source, ['remote', '-v']));
        self::assertSame($beforeTags, $this->git($source, ['tag']));
        self::assertSame('HEAD', $this->git($source, ['rev-parse', '--abbrev-ref', 'HEAD']));
    }

    public function testGitWorktreeUsesTheEffectiveRepositoryRoot(): void
    {
        $main = $this->createCommittedModule('foo');
        $worktree = $this->directory . '/worktree';
        $this->git($main, ['worktree', 'add', '--detach', $worktree, 'HEAD']);
        $module = $this->module($worktree, 'foo');
        $inspection = (new InspectLocalProjectSourceAction())($module);

        self::assertTrue($inspection->repositoryExists);
        self::assertSame(realpath($worktree), realpath($inspection->gitRoot));
        self::assertFalse(is_dir($worktree . '/.git'));
        self::assertFalse($inspection->bootstrapRequired);
    }

    public function testNestedRepositoryWinsOverTheParentRepository(): void
    {
        $parent = $this->directory . '/parent';
        $nested = $parent . '/nested';
        $source = $nested . '/foo';
        mkdir($source, 0700, true);
        $this->git($parent, ['init']);
        $this->configureIdentity($parent);
        file_put_contents($parent . '/README.md', 'parent');
        $this->git($parent, ['add', '--', 'README.md']);
        $this->git($parent, ['commit', '-m', 'Parent']);
        $this->git($nested, ['init']);
        $this->configureIdentity($nested);
        file_put_contents($source . '/foo.info.yml', "name: Foo\ntype: module\ncore_version_requirement: ^11\n");
        file_put_contents($source . '/foo.module', "<?php\n");
        $this->git($nested, ['add', '--', 'foo']);
        $this->git($nested, ['commit', '-m', 'Nested module']);

        $inspection = (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));

        self::assertSame(realpath($nested), realpath($inspection->gitRoot));
        self::assertSame('foo/', $inspection->modulePrefix);
        self::assertFalse($inspection->bootstrapRequired);
    }

    public function testSensitiveBootstrapCandidateIsRejectedBeforeCommit(): void
    {
        $source = $this->createModule('foo');
        file_put_contents($source . '/auth.json', '{}');

        $this->expectExceptionMessage('Potentially sensitive tracked file');
        (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));
    }

    public function testMalformedModuleComposerIsRejectedBeforeBootstrap(): void
    {
        $source = $this->createModule('foo');
        file_put_contents($source . '/composer.json', '{invalid');

        $this->expectExceptionMessage('Module composer.json is not valid JSON');
        (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));
    }

    public function testWrongModuleComposerPackageIsRejectedBeforeBootstrap(): void
    {
        $source = $this->createModule('foo');
        file_put_contents($source . '/composer.json', '{"name":"vendor/foo","type":"library"}');

        $this->expectExceptionMessage('must declare name "drupal/foo" and type "drupal-module"');
        (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));
    }

    private function createModule(string $machineName, ?string $path = null): string
    {
        $path ??= $this->directory . '/' . $machineName;
        mkdir($path, 0700, true);
        file_put_contents($path . '/' . $machineName . '.info.yml', "name: Foo\ntype: module\ncore_version_requirement: ^11\n");
        file_put_contents($path . '/' . $machineName . '.module', "<?php\n");
        return $path;
    }

    private function createCommittedModule(string $machineName, ?string $path = null, ?string $root = null, ?string $webRoot = null): string
    {
        $path ??= $this->directory . '/' . $machineName;
        $root ??= $path;
        $this->createModule($machineName, $path);
        $this->git($root, ['init']);
        $this->configureIdentity($root);
        $relative = $root === $path ? '.' : ltrim(str_replace($root, '', $path), '/');
        $this->git($root, ['add', '--', $relative]);
        $this->git($root, ['commit', '-m', 'Initial module']);
        return $path;
    }

    private function configureIdentity(string $root): void
    {
        $this->git($root, ['config', 'user.name', 'Test User']);
        $this->git($root, ['config', 'user.email', 'test@example.test']);
    }

    private function module(string $path, string $machineName, ?string $projectRoot = null, ?string $webRoot = null): ModuleContext
    {
        return new ModuleContext(
            new ResolvedModule($path, $machineName, $path . '/' . $machineName . '.info.yml', $projectRoot, $webRoot),
            new ModuleInfo('Foo', 'module', '^11', []),
        );
    }

    /** @return list<string> */
    private function treeNames(string $root, string $tree = 'HEAD'): array
    {
        return array_values(array_filter(explode("\n", $this->git($root, ['ls-tree', '--name-only', $tree]))));
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): string
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(30);
        $process->mustRun();
        return trim($process->getOutput());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
