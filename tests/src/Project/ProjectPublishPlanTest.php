<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ProjectPublishPlan;
use mglaman\DrupalOrg\Project\ProjectPublishState;
use mglaman\DrupalOrg\Project\ProjectPublishStep;
use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectPublishPlan::class)]
final class ProjectPublishPlanTest extends TestCase
{
    private ProjectReleaseBranch $branch;

    private ProjectReleaseVersion $version;

    protected function setUp(): void
    {
        $this->branch = ProjectReleaseBranch::fromString('1.x');
        $this->version = ProjectReleaseVersion::fromString('1.0.0');
    }

    public function testRepositoryPending(): void
    {
        self::assertSame(ProjectPublishStep::WAIT_FOR_REPOSITORY, ProjectPublishPlan::fromState(new ProjectPublishState(false))->nextStep);
    }

    public function testOldBranchPublishesSource(): void
    {
        self::assertSame(ProjectPublishStep::PUBLISH_SOURCE, ProjectPublishPlan::fromState($this->state(branchMatches: false))->nextStep);
    }

    public function testMatchingBranchCreatesTag(): void
    {
        self::assertSame(ProjectPublishStep::CREATE_TAG, ProjectPublishPlan::fromState($this->state(branchMatches: true))->nextStep);
    }

    public function testTagConflictFails(): void
    {
        self::assertSame(ProjectPublishStep::TAG_CONFLICT, ProjectPublishPlan::fromState($this->state(tagExists: true))->nextStep);
    }

    public function testValidTagWithAdvancedBranchCreatesRelease(): void
    {
        self::assertSame(ProjectPublishStep::CREATE_RELEASE, ProjectPublishPlan::fromState($this->state(branchMatches: false, tagExists: true, tagMatches: true))->nextStep);
    }

    public function testReleaseThenComposer(): void
    {
        self::assertSame(ProjectPublishStep::VERIFY_COMPOSER, ProjectPublishPlan::fromState($this->state(branchMatches: false, tagExists: true, tagMatches: true, releaseExists: true))->nextStep);
    }

    public function testComplete(): void
    {
        self::assertSame(ProjectPublishStep::COMPLETE, ProjectPublishPlan::fromState($this->state(branchMatches: false, tagExists: true, tagMatches: true, releaseExists: true, composer: true))->nextStep);
    }

    private function state(
        bool $branchMatches = false,
        bool $tagExists = false,
        bool $tagMatches = false,
        bool $releaseExists = false,
        bool $composer = false,
    ): ProjectPublishState {
        return new ProjectPublishState(
            true,
            $this->branch,
            $this->version,
            'commit',
            'tree',
            true,
            $branchMatches ? 'tree' : 'other-tree',
            $branchMatches,
            $tagExists,
            $tagMatches ? 'tree' : 'other-tree',
            $tagMatches,
            $releaseExists,
            $composer,
        );
    }
}
