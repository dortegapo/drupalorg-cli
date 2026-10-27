<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ProjectPublishPlan;
use mglaman\DrupalOrg\Project\ProjectPublishState;
use mglaman\DrupalOrg\Project\ProjectPublishStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectPublishPlan::class)]
final class ProjectPublishPlanTest extends TestCase
{
    #[DataProvider('states')]
    public function testChoosesNextSafeStep(ProjectPublishState $state, ProjectPublishStep $expected): void
    {
        self::assertSame($expected, ProjectPublishPlan::fromState($state)->nextStep);
    }

    /** @return array<string, array{ProjectPublishState, ProjectPublishStep}> */
    public static function states(): array
    {
        return [
            'repository pending' => [new ProjectPublishState(true, true, false), ProjectPublishStep::WAIT_FOR_REPOSITORY],
            'publish source' => [new ProjectPublishState(true, true, true), ProjectPublishStep::PUBLISH_SOURCE],
            'create tag' => [new ProjectPublishState(true, true, true, sourcePublished: true), ProjectPublishStep::CREATE_TAG],
            'create release' => [new ProjectPublishState(true, true, true, sourcePublished: true, tagExists: true), ProjectPublishStep::CREATE_RELEASE],
            'verify composer' => [new ProjectPublishState(true, true, true, sourcePublished: true, tagExists: true, releaseExists: true), ProjectPublishStep::VERIFY_COMPOSER],
            'complete' => [new ProjectPublishState(true, true, true, sourcePublished: true, tagExists: true, releaseExists: true, composerPackageVisible: true), ProjectPublishStep::COMPLETE],
        ];
    }
}
