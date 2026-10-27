<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/** Decides the next safe operation without embedding CLI concerns. */
final class ProjectPublishPlan
{
    private function __construct(public readonly ProjectPublishStep $nextStep)
    {
    }

    public static function fromState(ProjectPublishState $state): self
    {
        if (!$state->repositoryReachable) {
            return new self(ProjectPublishStep::WAIT_FOR_REPOSITORY);
        }
        if ($state->tagExists && !$state->tagMatchesSource) {
            return new self(ProjectPublishStep::TAG_CONFLICT);
        }
        if (!$state->tagExists && !$state->branchMatchesSource) {
            return new self(ProjectPublishStep::PUBLISH_SOURCE);
        }
        if (!$state->tagExists) {
            return new self(ProjectPublishStep::CREATE_TAG);
        }
        if (!$state->releaseExists) {
            return new self(ProjectPublishStep::CREATE_RELEASE);
        }
        if (!$state->composerPackageVisible) {
            return new self(ProjectPublishStep::VERIFY_COMPOSER);
        }

        return new self(ProjectPublishStep::COMPLETE);
    }
}
