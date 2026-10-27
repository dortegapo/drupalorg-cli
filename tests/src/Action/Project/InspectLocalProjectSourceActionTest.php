<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Action\Project;

use mglaman\DrupalOrg\Action\Project\InspectLocalProjectSourceAction;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ModuleInfo;
use mglaman\DrupalOrg\Project\ResolvedModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(InspectLocalProjectSourceAction::class)]
final class InspectLocalProjectSourceActionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/drupalorg-local-source-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testUnbornHeadHasAnActionableError(): void
    {
        $source = $this->directory . '/source';
        mkdir($source);
        $this->git($source, ['init']);
        $module = $this->module($source, 'foo');
        file_put_contents($source . '/foo.info.yml', "name: Foo\ntype: module\ncore_version_requirement: ^11\n");
        file_put_contents($source . '/foo.module', "<?php\n");

        try {
            (new InspectLocalProjectSourceAction())($module);
            self::fail('Expected unborn HEAD failure.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Module source has no Git commit yet', $exception->getMessage());
            self::assertStringNotContainsString('ambiguous argument', $exception->getMessage());
        }
    }

    public function testModuleAbsentFromHeadIsRejected(): void
    {
        $source = $this->directory . '/source';
        mkdir($source);
        $this->git($source, ['init']);
        $this->git($source, ['config', 'user.name', 'Test']);
        $this->git($source, ['config', 'user.email', 'test@example.test']);
        file_put_contents($source . '/README.md', 'root');
        $this->git($source, ['add', 'README.md']);
        $this->git($source, ['commit', '-m', 'Root commit']);
        mkdir($source . '/web/modules/custom/foo', 0700, true);
        $module = $this->module($source . '/web/modules/custom/foo', 'foo', $source, 'web');
        file_put_contents($source . '/web/modules/custom/foo/foo.info.yml', "name: Foo\ntype: module\ncore_version_requirement: ^11\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module source is not committed in HEAD');
        (new InspectLocalProjectSourceAction())($module);
    }

    public function testDirtyModuleIsRejected(): void
    {
        $source = $this->committedSource();
        file_put_contents($source . '/foo.module', "<?php\nchanged\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module source contains uncommitted changes');
        (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));
    }

    public function testUntrackedModuleFileIsRejected(): void
    {
        $source = $this->committedSource();
        file_put_contents($source . '/untracked.txt', 'untracked');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module source contains uncommitted changes');
        (new InspectLocalProjectSourceAction())($this->module($source, 'foo'));
    }

    public function testUnrelatedDirtyFileIsAllowed(): void
    {
        $root = $this->directory . '/monorepo';
        mkdir($root . '/web/modules/custom/foo', 0700, true);
        $this->git($root, ['init']);
        $this->git($root, ['config', 'user.name', 'Test']);
        $this->git($root, ['config', 'user.email', 'test@example.test']);
        file_put_contents($root . '/web/modules/custom/foo/foo.info.yml', "name: Foo\ntype: module\ncore_version_requirement: ^11\n");
        file_put_contents($root . '/web/modules/custom/foo/foo.module', "<?php\n");
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '-m', 'Module']);
        file_put_contents($root . '/UNRELATED.txt', 'dirty');

        $result = (new InspectLocalProjectSourceAction())($this->module(
            $root . '/web/modules/custom/foo',
            'foo',
            $root,
            'web',
        ));

        self::assertSame($root, $result->gitRoot);
        self::assertSame('web/modules/custom/foo/', $result->modulePrefix);
        self::assertNotSame('', $result->sourceCommitSha);
        self::assertNotSame('', $result->sourceTreeSha);
    }

    public function testNoGitRepositoryHasAnActionableError(): void
    {
        $source = $this->directory . '/source';
        mkdir($source);
        $module = $this->module($source, 'foo');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module source is not inside a Git repository');
        (new InspectLocalProjectSourceAction())($module);
    }

    private function committedSource(): string
    {
        $source = $this->directory . '/source';
        mkdir($source);
        $this->git($source, ['init']);
        $this->git($source, ['config', 'user.name', 'Test']);
        $this->git($source, ['config', 'user.email', 'test@example.test']);
        file_put_contents($source . '/foo.info.yml', "name: Foo\ntype: module\ncore_version_requirement: ^11\n");
        file_put_contents($source . '/foo.module', "<?php\n");
        $this->git($source, ['add', '.']);
        $this->git($source, ['commit', '-m', 'Module']);
        return $source;
    }

    private function module(string $path, string $machineName, ?string $projectRoot = null, ?string $webRoot = null): ModuleContext
    {
        return new ModuleContext(
            new ResolvedModule($path, $machineName, $path . '/' . $machineName . '.info.yml', $projectRoot, $webRoot),
            new ModuleInfo('Foo', 'module', '^11', []),
        );
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
