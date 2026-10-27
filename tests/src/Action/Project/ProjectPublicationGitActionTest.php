<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Action\Project;

use mglaman\DrupalOrg\Action\Project\CreateProjectTagAction;
use mglaman\DrupalOrg\Action\Project\InspectLocalProjectSourceAction;
use mglaman\DrupalOrg\Action\Project\InspectProjectRepositoryAction;
use mglaman\DrupalOrg\Action\Project\InspectProjectSourceAction;
use mglaman\DrupalOrg\Action\Project\PublishProjectSourceAction;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ModuleInfo;
use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Project\ProjectRepositoryUrls;
use mglaman\DrupalOrg\Project\ResolvedModule;
use mglaman\DrupalOrg\Result\Project\ProjectLocalSourceResult;
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

    public function testRepositoryFailureClassificationSeparatesMissingRepositoriesFromNetworkErrors(): void
    {
        self::assertSame('repository_not_ready', InspectProjectRepositoryAction::classifyFailure('fatal: repository not found'));
        self::assertSame('repository_not_ready', InspectProjectRepositoryAction::classifyFailure('Project foo could not be found (HTTP 404)'));
        self::assertSame('network_failure', InspectProjectRepositoryAction::classifyFailure('Could not resolve host: git.drupal.org'));
        self::assertSame('network_failure', InspectProjectRepositoryAction::classifyFailure('SSL connection timeout'));
    }

    public function testPushFailureClassificationIsSafeAndActionable(): void
    {
        self::assertStringContainsString('ssh -T git@git.drupal.org', PublishProjectSourceAction::classifyPushFailure('Permission denied (publickey).'));
        self::assertStringContainsString('Run project:publish again', PublishProjectSourceAction::classifyPushFailure('rejected (non-fast-forward)'));
        self::assertStringNotContainsString('secret-value', PublishProjectSourceAction::classifyPushFailure('remote error token=secret-value'));
        self::assertStringNotContainsString('secret-value', PublishProjectSourceAction::classifyPushFailure('remote https://user:secret-value@example.test/repository.git failed'));
    }

    public function testPublishesOnlyCommittedModuleFilesAndTagsByTree(): void
    {
        $branch = ProjectReleaseBranch::fromString('1.x');
        $version = ProjectReleaseVersion::fromString('1.0.0');
        $empty = (new InspectProjectRepositoryAction($this->urls))('foo');
        self::assertTrue($empty->reachable);
        self::assertSame([], $empty->branches);
        $emptyStatus = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $empty);
        self::assertSame(3, $emptyStatus->filesAdded);
        self::assertSame(0, $emptyStatus->filesModified);
        self::assertSame(0, $emptyStatus->filesDeleted);
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
        self::assertSame(
            $this->git($this->directory, ['--git-dir=' . $this->remote, 'rev-parse', 'refs/tags/1.0.0']),
            $tag->commitSha,
        );
    }

    public function testPublishingFailsWhenBranchChangesAfterInspection(): void
    {
        $branch = ProjectReleaseBranch::fromString('1.x');
        $version = ProjectReleaseVersion::fromString('1.0.0');
        $publisher = new PublishProjectSourceAction($this->urls);
        $publisher($this->module, 'foo', $branch, $version);
        $repository = (new InspectProjectRepositoryAction($this->urls))('foo');
        $status = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $repository);

        $updater = $this->cloneBranch('race-updater', $branch);
        file_put_contents($updater . '/remote-change.txt', 'remote');
        $this->git($updater, ['add', '.']);
        $this->git($updater, ['commit', '-m', 'Remote change']);
        $this->git($updater, ['push', 'origin', 'HEAD:refs/heads/1.x']);

        try {
            $publisher($this->module, 'foo', $branch, $version, $status);
            self::fail('Expected stale publication plan failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                "Drupal.org branch changed after source inspection.\n\nThe publication plan is stale.\nRun project:publish again to review the new remote changes.",
                $exception->getMessage(),
            );
        }
        self::assertContains('remote-change.txt', $this->treeNames($this->remote, 'refs/heads/1.x'));
    }

    public function testPublishingFailsWhenBranchAppearsAfterInspection(): void
    {
        $branch = ProjectReleaseBranch::fromString('1.x');
        $version = ProjectReleaseVersion::fromString('1.0.0');
        $repository = (new InspectProjectRepositoryAction($this->urls))('foo');
        $status = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $repository);
        self::assertFalse($status->branchExists);
        $publisher = new PublishProjectSourceAction($this->urls);
        $publisher($this->module, 'foo', $branch, $version);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The publication plan is stale.');
        $publisher($this->module, 'foo', $branch, $version, $status);
    }

    public function testPublicationStopsWhenLocalSourceChangesAfterInspection(): void
    {
        $branch = ProjectReleaseBranch::fromString('1.x');
        $version = ProjectReleaseVersion::fromString('1.0.0');
        $publisher = new PublishProjectSourceAction($this->urls);
        $publisher($this->module, 'foo', $branch, $version);
        $repository = (new InspectProjectRepositoryAction($this->urls))('foo');
        $status = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $repository);
        $validatedRemoteCommit = $status->branchCommitSha;
        self::assertNotNull($validatedRemoteCommit);

        file_put_contents($this->source . '/foo.module', "<?php\nlocal change\n");
        $this->git($this->source, ['add', 'foo.module']);
        $this->git($this->source, ['commit', '-m', 'Local publication change']);
        try {
            $publisher($this->module, 'foo', $branch, $version, $status);
            self::fail('Expected local source change failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                "Module source changed during publication.\n\nRun project:publish again to review and publish the current committed source.",
                $exception->getMessage(),
            );
        }
        self::assertSame($validatedRemoteCommit, $this->git($this->remote, ['rev-parse', 'refs/heads/1.x']));
    }

    public function testPublicationStopsWhenSourceChangesDuringStaging(): void
    {
        $branch = ProjectReleaseBranch::fromString('1.x');
        $version = ProjectReleaseVersion::fromString('1.0.0');
        $status = (new InspectProjectSourceAction($this->urls))(
            $this->module,
            $branch,
            $version,
            (new InspectProjectRepositoryAction($this->urls))('foo'),
        );
        $realInspector = new InspectLocalProjectSourceAction();
        $calls = 0;
        $inspector = $this->createMock(InspectLocalProjectSourceAction::class);
        $inspector->expects(self::exactly(2))->method('__invoke')->willReturnCallback(
            function (ModuleContext $module) use (&$calls, $realInspector): ProjectLocalSourceResult {
                ++$calls;
                $result = $realInspector($module);
                if ($calls === 2) {
                    file_put_contents($this->source . '/foo.module', "<?php\nchanged during staging\n");
                    $this->git($this->source, ['add', '--', 'foo.module']);
                    $this->git($this->source, ['commit', '-m', 'Changed during staging']);
                    return $realInspector($module);
                }

                return $result;
            },
        );

        try {
            (new PublishProjectSourceAction($this->urls, $inspector))($this->module, 'foo', $branch, $version, $status);
            self::fail('Expected source change failure during staging.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                "Module source changed during publication.\n\nRun project:publish again to review and publish the current committed source.",
                $exception->getMessage(),
            );
        }
        self::assertSame('', $this->git($this->remote, ['for-each-ref']));
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
        file_put_contents($root . '/UNRELATED-DIRTY.txt', 'not part of the module');
        (new PublishProjectSourceAction($urls))($module, 'foo', ProjectReleaseBranch::fromString('1.x'), ProjectReleaseVersion::fromString('1.0.0'));

        self::assertSame(['foo.info.yml', 'foo.module', 'src'], $this->treeNames($remote, 'refs/heads/1.x'));
        $names = $this->treeNames($remote, 'refs/heads/1.x');
        self::assertNotContains('composer.json', $names);
        self::assertNotContains('.ddev', $names);
        self::assertNotContains('web', $names);
    }

    public function testMissingGitIdentityIsReportedBeforeMutation(): void
    {
        $this->git($this->source, ['config', '--unset', 'user.name']);
        $this->git($this->source, ['config', '--unset', 'user.email']);
        $home = $this->directory . '/empty-home';
        mkdir($home, 0700);
        $oldHome = $_ENV['HOME'] ?? null;
        $oldServerHome = $_SERVER['HOME'] ?? null;
        $oldGlobal = $_ENV['GIT_CONFIG_GLOBAL'] ?? null;
        $oldServerGlobal = $_SERVER['GIT_CONFIG_GLOBAL'] ?? null;
        $_ENV['HOME'] = $_SERVER['HOME'] = $home;
        $_ENV['GIT_CONFIG_GLOBAL'] = $_SERVER['GIT_CONFIG_GLOBAL'] = '/dev/null';
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Git identity is not configured');
            (new PublishProjectSourceAction($this->urls))(
                $this->module,
                'foo',
                ProjectReleaseBranch::fromString('1.x'),
                ProjectReleaseVersion::fromString('1.0.0'),
            );
        } finally {
            $this->restoreEnv('HOME', $oldHome, $oldServerHome);
            $this->restoreEnv('GIT_CONFIG_GLOBAL', $oldGlobal, $oldServerGlobal);
        }
        self::assertSame('', $this->git($this->remote, ['show-ref']));
    }

    public function testMissingGitIdentityDoesNotBlockAnAlreadyPublishedRerun(): void
    {
        $publisher = new PublishProjectSourceAction($this->urls);
        $publisher($this->module, 'foo', ProjectReleaseBranch::fromString('1.x'), ProjectReleaseVersion::fromString('1.0.0'));
        $this->git($this->source, ['config', '--unset', 'user.name']);
        $this->git($this->source, ['config', '--unset', 'user.email']);
        $home = $this->directory . '/empty-home-rerun';
        mkdir($home, 0700);
        $oldHome = $_ENV['HOME'] ?? null;
        $oldServerHome = $_SERVER['HOME'] ?? null;
        $oldGlobal = $_ENV['GIT_CONFIG_GLOBAL'] ?? null;
        $oldServerGlobal = $_SERVER['GIT_CONFIG_GLOBAL'] ?? null;
        $_ENV['HOME'] = $_SERVER['HOME'] = $home;
        $_ENV['GIT_CONFIG_GLOBAL'] = $_SERVER['GIT_CONFIG_GLOBAL'] = '/dev/null';
        try {
            $result = $publisher(
                $this->module,
                'foo',
                ProjectReleaseBranch::fromString('1.x'),
                ProjectReleaseVersion::fromString('1.0.0'),
            );
        } finally {
            $this->restoreEnv('HOME', $oldHome, $oldServerHome);
            $this->restoreEnv('GIT_CONFIG_GLOBAL', $oldGlobal, $oldServerGlobal);
        }

        self::assertTrue($result->alreadyUpToDate);
    }

    public function testTagAppearingAfterInspectionWithSameTreeIsIdempotent(): void
    {
        $branch = ProjectReleaseBranch::fromString('1.x');
        $version = ProjectReleaseVersion::fromString('1.0.0');
        $publisher = new PublishProjectSourceAction($this->urls);
        $publisher($this->module, 'foo', $branch, $version);
        $repository = (new InspectProjectRepositoryAction($this->urls))('foo');
        $status = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $repository);
        $creator = new CreateProjectTagAction($this->urls);
        $creator('foo', $branch, $version, $status);
        $result = $creator('foo', $branch, $version, $status);

        self::assertFalse($result->created);
        self::assertSame($this->git($this->remote, ['rev-parse', 'refs/tags/1.0.0']), $result->commitSha);
    }

    public function testTagAppearingAfterInspectionWithDifferentTreeFails(): void
    {
        $branch = ProjectReleaseBranch::fromString('1.x');
        $version = ProjectReleaseVersion::fromString('1.0.0');
        $publisher = new PublishProjectSourceAction($this->urls);
        $publisher($this->module, 'foo', $branch, $version);
        $repository = (new InspectProjectRepositoryAction($this->urls))('foo');
        $status = (new InspectProjectSourceAction($this->urls))($this->module, $branch, $version, $repository);

        $updater = $this->cloneBranch('tag-race-updater', $branch);
        file_put_contents($updater . '/different.txt', 'different');
        $this->git($updater, ['add', '.']);
        $this->git($updater, ['commit', '-m', 'Different tag source']);
        $this->git($updater, ['tag', $version->value]);
        $this->git($updater, ['push', 'origin', 'refs/tags/' . $version->value]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Release tags are immutable');
        (new CreateProjectTagAction($this->urls))('foo', $branch, $version, $status);
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

    public function testUntrackedModuleFileIsBlockedBeforeRemoteMutation(): void
    {
        file_put_contents($this->source . '/untracked.txt', 'not committed');
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

    private function cloneBranch(string $name, ProjectReleaseBranch $branch): string
    {
        $clone = $this->directory . '/' . $name;
        $this->git($this->directory, ['clone', '--branch', $branch->name, $this->remote, $clone]);
        $this->git($clone, ['config', 'user.name', 'Updater']);
        $this->git($clone, ['config', 'user.email', 'updater@example.test']);
        return $clone;
    }

    private function restoreEnv(string $key, ?string $env, ?string $server): void
    {
        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }
        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }
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
