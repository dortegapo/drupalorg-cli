<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Entity\Project;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Project\ProjectRepositoryUrls;
use mglaman\DrupalOrg\Result\Project\ProjectLocalSourceResult;
use mglaman\DrupalOrg\Result\Project\ProjectPublishStateResult;
use mglaman\DrupalOrg\Result\Project\ProjectRepositoryResult;
use mglaman\DrupalOrg\Result\Project\ProjectSourceStatusResult;

/** Builds one consistent, resumable publication snapshot. */
final class InspectProjectPublishStateAction implements ActionInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly ?InspectProjectRepositoryAction $repositoryInspector = null,
        private readonly ?InspectProjectSourceAction $sourceInspector = null,
        private readonly ?GetProjectReleasesAction $releasesAction = null,
        private readonly ?ProjectRepositoryUrls $repositoryUrls = null,
        private readonly ?\Closure $sleeper = null,
    ) {
    }

    public function __invoke(
        ModuleContext $module,
        Project $project,
        ProjectReleaseBranch $branch,
        ProjectReleaseVersion $version,
        ProjectLocalSourceResult $localSource,
    ): ProjectPublishStateResult {
        $repository = $this->inspectRepositoryWithRetry($module->location->machineName);
        if (!$repository->reachable && $repository->failure !== 'repository_not_ready') {
            throw new \RuntimeException(
                'Could not inspect Drupal.org repository: ' . ($repository->failure ?? 'network_failure') . '.',
            );
        }

        $source = null;
        if ($repository->reachable) {
            $source = ($this->sourceInspector ?? new InspectProjectSourceAction($this->repositoryUrls))(
                $module,
                $branch,
                $version,
                $repository,
                $localSource,
            );
        }

        $releaseExists = false;
        if ($repository->reachable) {
            foreach (($this->releasesAction ?? new GetProjectReleasesAction($this->client))($project, true, true)->releases as $release) {
                if ($release->fieldReleaseVersion === $version->value) {
                    $releaseExists = true;
                    break;
                }
            }
        }

        return $this->state($repository, $source, $branch, $version, $releaseExists);
    }

    public function inspectAfterMutation(
        ModuleContext $module,
        Project $project,
        ProjectReleaseBranch $branch,
        ProjectReleaseVersion $version,
        ProjectLocalSourceResult $localSource,
    ): ProjectPublishStateResult {
        return $this($module, $project, $branch, $version, $localSource);
    }

    private function inspectRepositoryWithRetry(string $machineName): ProjectRepositoryResult
    {
        $action = $this->repositoryInspector ?? new InspectProjectRepositoryAction($this->repositoryUrls);
        $result = $action($machineName);
        if ($result->failure !== 'repository_not_ready') {
            return $result;
        }
        foreach ([2, 4, 8, 15] as $delay) {
            ($this->sleeper ?? static function (int $seconds): void {
                sleep($seconds);
            })($delay);
            $result = $action($machineName);
            if ($result->reachable || $result->failure !== 'repository_not_ready') {
                break;
            }
        }

        return $result;
    }

    private function state(
        ProjectRepositoryResult $repository,
        ?ProjectSourceStatusResult $source,
        ProjectReleaseBranch $branch,
        ProjectReleaseVersion $version,
        bool $releaseExists,
    ): ProjectPublishStateResult {
        return new ProjectPublishStateResult(
            $repository->reachable,
            $branch,
            $version,
            $source?->localSourceCommitSha,
            $source?->localTreeSha,
            $source?->branchExists ?? false,
            $source?->branchTreeSha,
            $source?->branchMatchesSource ?? false,
            $source?->tagExists ?? false,
            $source?->tagTreeSha,
            $source?->tagMatchesSource ?? false,
            $releaseExists,
            false,
            $source?->filesAdded ?? 0,
            $source?->filesModified ?? 0,
            $source?->filesDeleted ?? 0,
            $source?->branchCommitSha,
            $source?->tagCommitSha,
        );
    }
}
