<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Action\Project;

use mglaman\DrupalOrg\Action\Project\CreateProjectTagAction;
use mglaman\DrupalOrg\Action\Project\InspectProjectRepositoryAction;
use mglaman\DrupalOrg\Action\Project\InspectProjectSourceAction;
use mglaman\DrupalOrg\Action\Project\PublishProjectSourceAction;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ModuleInfo;
use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Project\ProjectRepositoryUrls;
use mglaman\DrupalOrg\Project\ResolvedModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(InspectProjectRepositoryAction::class)]
#[CoversClass(InspectProjectSourceAction::class)]
#[CoversClass(PublishProjectSourceAction::class)]
#[CoversClass(CreateProjectTagAction::class)]
final class ProjectPublicationGitActionTest extends TestCase
{
    private string $directory;

    private string $source;

    private string $remote;

    private ModuleContext $module;

    private ProjectRepositoryUrls $urls;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/drupalorg-git-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->source = $this->directory . '/source';
        $this->remote = $this->directory . '/remote.git';
        mkdir($this->source);
        $this->git($this->directory, ['init', '--bare', $this->remote]);
        $this->git($this->source, ['init']);
        $this->git($this->source, ['config', 'user.name', 'Publication Test']);
        $this->git($this->source, ['config', 'user.email', 'publication@example.test']);
        file_put_contents($this->source . '/foo.info.yml', "name: Foo\ntype: module\ncore_version_requirement: ^11\n");
        file_put_contents($this->source . '/foo.module', "<?php\n");
        mkdir($this->source . '/src');
        file_put_contents($this->source . '/src/Foo.php', "<?php\n");
        $this->git($this->source, ['add', '.']);
        $this->git($this->source, ['commit', '-m', 'Initial module']);
        $this->module = new ModuleContext(
            new ResolvedModule($this->source, 'foo', $this->source . '/foo.info.yml', null, null),
            new ModuleInfo('Foo', 'module', '^11', []),
        );
        $this->urls = new ProjectRepositoryUrls($this->remote, $this->remote);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testPublishesOnlyCommittedModuleFilesAndTagsByTree(): void
    {
        $branch = ProjectReleaseBranch::fromString('1.x');
        $version = ProjectReleaseVersion::fromString('1.0.0');
        $empty = (new InspectProjectRepositoryAction($this->urls))('foo');
        self::assertTrue($empty->reachable);
        self::assertSame([], $empty->branches);
        $publisher = new PublishProjectSourceAction($this->urls);
        $published = $publisher($this->module, 'foo', $branch, $version);
        self::assertTrue($published->created);
        $same = $publisher($this->module, 'foo', $branch, $version);
        self::assertTrue($same->alreadyUpToDate);
        file_put_contents($this->source . '/foo.module', "<?php\nupdated\n");
        $this->git($this->source, ['add', 'foo.module']);
        $this->git($this->source, ['commit', '-m', 'Update module']);
        $updated = $publisher($this->module, 'foo', $branch, $version);
        self::assertFalse($updated->alreadyUpToDate);

        $repository = (new InspectProjectRepositoryAction($this->urls))('foo');
        $status = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $repository);
        self::assertTrue($status->branchMatchesSource);
        self::assertSame(['foo.info.yml', 'foo.module', 'src'], $this->treeNames($this->remote, 'refs/heads/1.x'));

        $tag = (new CreateProjectTagAction($this->urls))('foo', $branch, $version, $status);
        self::assertTrue($tag->created);
        $taggedRepository = (new InspectProjectRepositoryAction($this->urls))('foo');
        $taggedStatus = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $taggedRepository);
        self::assertTrue($taggedStatus->tagMatchesSource);
    }

    public function testBranchCanAdvanceAfterMatchingTag(): void
    {
        $branch = ProjectReleaseBranch::fromString('1.x');
        $version = ProjectReleaseVersion::fromString('1.0.0');
        (new PublishProjectSourceAction($this->urls))($this->module, 'foo', $branch, $version);
        $repository = (new InspectProjectRepositoryAction($this->urls))('foo');
        $status = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $repository);
        (new CreateProjectTagAction($this->urls))('foo', $branch, $version, $status);

        $updater = $this->directory . '/updater';
        $this->git($this->directory, ['clone', '--branch', '1.x', $this->remote, $updater]);
        $this->git($updater, ['config', 'user.name', 'Updater']);
        $this->git($updater, ['config', 'user.email', 'updater@example.test']);
        file_put_contents($updater . '/new-file.txt', 'later');
        $this->git($updater, ['add', '.']);
        $this->git($updater, ['commit', '-m', 'Advance branch']);
        $this->git($updater, ['push', 'origin', 'HEAD:refs/heads/1.x']);

        $advanced = (new InspectProjectRepositoryAction($this->urls))('foo');
        $advancedStatus = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $advanced);
        self::assertFalse($advancedStatus->branchMatchesSource);
        self::assertTrue($advancedStatus->tagMatchesSource);
    }

    public function testExistingTagForDifferentTreeIsImmutableFailure(): void
    {
        $branch = ProjectReleaseBranch::fromString('1.x');
        $version = ProjectReleaseVersion::fromString('1.0.0');
        (new PublishProjectSourceAction($this->urls))($this->module, 'foo', $branch, $version);
        $repository = (new InspectProjectRepositoryAction($this->urls))('foo');
        $status = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $repository);
        (new CreateProjectTagAction($this->urls))('foo', $branch, $version, $status);
        file_put_contents($this->source . '/foo.module', "<?php\nchanged\n");
        $this->git($this->source, ['add', 'foo.module']);
        $this->git($this->source, ['commit', '-m', 'Different source']);
        $changedRepository = (new InspectProjectRepositoryAction($this->urls))('foo');
        $changedStatus = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $changedRepository);
        self::assertFalse($changedStatus->tagMatchesSource);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Release tags are immutable');
        (new CreateProjectTagAction($this->urls))('foo', $branch, $version, $changedStatus);
    }

    public function testNestedModulePublishesOnlyItsSubtree(): void
    {
        $root = $this->directory . '/monorepo';
        $remote = $this->directory . '/nested-remote.git';
        mkdir($root . '/web/modules/custom/foo/src', 0700, true);
        $this->git($this->directory, ['init', '--bare', $remote]);
        $this->git($root, ['init']);
        $this->git($root, ['config', 'user.name', 'Publication Test']);
        $this->git($root, ['config', 'user.email', 'publication@example.test']);
        file_put_contents($root . '/composer.json', '{}');
        mkdir($root . '/.ddev');
        file_put_contents($root . '/.ddev/config.yaml', 'name: root');
        file_put_contents($root . '/web/modules/custom/foo/foo.info.yml', "name: Foo\ntype: module\ncore_version_requirement: ^11\n");
        file_put_contents($root . '/web/modules/custom/foo/foo.module', "<?php\n");
        file_put_contents($root . '/web/modules/custom/foo/src/Foo.php', "<?php\n");
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '-m', 'Nested module']);
        $module = new ModuleContext(
            new ResolvedModule($root . '/web/modules/custom/foo', 'foo', $root . '/web/modules/custom/foo/foo.info.yml', $root, 'web'),
            new ModuleInfo('Foo', 'module', '^11', []),
        );
        $urls = new ProjectRepositoryUrls($remote, $remote);
        (new PublishProjectSourceAction($urls))($module, 'foo', ProjectReleaseBranch::fromString('1.x'), ProjectReleaseVersion::fromString('1.0.0'));

        self::assertSame(['foo.info.yml', 'foo.module', 'src'], $this->treeNames($remote, 'refs/heads/1.x'));
        $names = $this->treeNames($remote, 'refs/heads/1.x');
        self::assertNotContains('composer.json', $names);
        self::assertNotContains('.ddev', $names);
        self::assertNotContains('web', $names);
    }

    public function testDirtyModuleIsBlockedBeforeRemoteMutation(): void
    {
        file_put_contents($this->source . '/foo.module', "<?php\n// uncommitted\n");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('uncommitted changes');
        (new PublishProjectSourceAction($this->urls))(
            $this->module,
            'foo',
            ProjectReleaseBranch::fromString('1.x'),
            ProjectReleaseVersion::fromString('1.0.0'),
        );
        self::assertSame('', $this->git($this->remote, ['show-ref']));
    }

    public function testSensitiveTrackedFilenameAndPrivateKeyContentAreBlocked(): void
    {
        file_put_contents($this->source . '/auth.json', '{}');
        $this->git($this->source, ['add', 'auth.json']);
        $this->git($this->source, ['commit', '-m', 'Sensitive file']);
        try {
            (new PublishProjectSourceAction($this->urls))(
                $this->module,
                'foo',
                ProjectReleaseBranch::fromString('1.x'),
                ProjectReleaseVersion::fromString('1.0.0'),
            );
            self::fail('Expected sensitive filename failure.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Potentially sensitive tracked file', $exception->getMessage());
        }
    }

    public function testPrivateKeyContentIsBlocked(): void
    {
        file_put_contents($this->source . '/notes.txt', "-----BEGIN OPENSSH PRIVATE KEY-----\nsecret\n");
        $this->git($this->source, ['add', 'notes.txt']);
        $this->git($this->source, ['commit', '-m', 'Key content']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Potential private key content');
        (new PublishProjectSourceAction($this->urls))(
            $this->module,
            'foo',
            ProjectReleaseBranch::fromString('1.x'),
            ProjectReleaseVersion::fromString('1.0.0'),
        );
    }

    /** @return list<string> */
    private function treeNames(string $repository, string $ref): array
    {
        $output = $this->git($this->directory, ['--git-dir=' . $repository, 'ls-tree', '--name-only', $ref]);
        return array_values(array_filter(explode("\n", trim($output))));
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
