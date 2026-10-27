<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Action\Project\GenerateProjectMetadataAction;
use mglaman\DrupalOrg\Action\Project\GetProjectAction;
use mglaman\DrupalOrg\Action\Project\GetProjectReleasesAction;
use mglaman\DrupalOrg\Action\Project\BootstrapProjectSourceAction;
use mglaman\DrupalOrg\Action\Project\InspectLocalProjectSourceAction;
use mglaman\DrupalOrg\Action\Project\InspectProjectRepositoryAction;
use mglaman\DrupalOrg\Action\Project\InspectProjectSourceAction;
use mglaman\DrupalOrg\Action\Project\PublishProjectSourceAction;
use mglaman\DrupalOrg\Action\Project\CreateProjectTagAction;
use mglaman\DrupalOrg\Action\Project\VerifyComposerPackageAction;
use mglaman\DrupalOrg\Action\Project\ResolveModuleAction;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ModuleInfoReader;
use mglaman\DrupalOrg\Project\ModuleLocator;
use mglaman\DrupalOrg\Project\ComposerContextLocator;
use mglaman\DrupalOrg\Project\ProjectPublishPlan;
use mglaman\DrupalOrg\Project\ProjectPublishState;
use mglaman\DrupalOrg\Project\ProjectPublishStep;
use mglaman\DrupalOrg\Project\ProjectReleaseBranch;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Project\ProjectRepositoryUrls;
use mglaman\DrupalOrg\Result\Project\ProjectRepositoryResult;
use mglaman\DrupalOrg\Result\Project\ProjectLocalSourceResult;
use mglaman\DrupalOrg\Result\Project\ProjectSourceStatusResult;
use mglaman\DrupalOrg\Result\Project\ComposerPackageResult;
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class Publish extends Command
{
    /**
     * @param ModuleReviewWorkflow|null $reviewWorkflow
     * @param GenerateProjectMetadataAction|null $metadataAction
     * @param DrupalOrgProjectCreationHandoff|null $projectCreationHandoff
     * @param ResolveModuleAction|null $moduleResolver
     * @param GetProjectAction|null $projectLookupAction
     */
    public function __construct(
        private readonly ?ModuleReviewWorkflow $reviewWorkflow = null,
        private readonly ?GenerateProjectMetadataAction $metadataAction = null,
        private readonly ?DrupalOrgProjectCreationHandoff $projectCreationHandoff = null,
        private readonly ?ResolveModuleAction $moduleResolver = null,
        private readonly ?GetProjectAction $projectLookupAction = null,
        private readonly ?InspectLocalProjectSourceAction $localSourceInspector = null,
        private readonly ?InspectProjectRepositoryAction $repositoryInspector = null,
        private readonly ?InspectProjectSourceAction $sourceInspector = null,
        private readonly ?PublishProjectSourceAction $sourcePublisher = null,
        private readonly ?CreateProjectTagAction $tagCreator = null,
        private readonly ?VerifyComposerPackageAction $composerVerifier = null,
        private readonly ?DrupalOrgReleaseCreationHandoff $releaseHandoff = null,
        private readonly ?GetProjectReleasesAction $releasesAction = null,
        private readonly ?ProjectRepositoryUrls $repositoryUrls = null,
        private readonly ?\Closure $sleeper = null,
        private readonly ?BootstrapProjectSourceAction $bootstrapSourceAction = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('project:publish')
            ->addArgument('module', InputArgument::REQUIRED, 'The module path')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be done without making publication changes.'
            )
            ->addOption(
                'no-browser',
                null,
                InputOption::VALUE_NONE,
                'Do not open the Drupal.org project creation page automatically.'
            )
            ->addOption('release-version', null, InputOption::VALUE_REQUIRED, 'Release version, for example 1.0.0.')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Release branch, for example 1.x.')
            ->setDescription('Publishes a project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modulePath = (string) $input->getArgument('module');
        $isDryRun = (bool) $input->getOption('dry-run');
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register([
            ProjectPublishProgressRenderer::PREPARING_MODULE => 'Preparing module',
            ProjectPublishProgressRenderer::CHECKING_LOCAL_SOURCE => 'Checking local Git source',
            ProjectPublishProgressRenderer::CHECKING_PROJECT => 'Checking Drupal.org project',
        ]);
        $progress->start(ProjectPublishProgressRenderer::PREPARING_MODULE);

        try {
            $moduleResult = ($this->moduleResolver ?? new ResolveModuleAction(
                new ModuleLocator(new ComposerContextLocator()),
                new ModuleInfoReader(),
            ))($modulePath);
            $module = $moduleResult->module;
        } catch (\RuntimeException $exception) {
            $progress->fail(ProjectPublishProgressRenderer::PREPARING_MODULE);
            $this->stdErr->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return self::FAILURE;
        }
        $progress->complete(ProjectPublishProgressRenderer::PREPARING_MODULE);

        $progress->start(ProjectPublishProgressRenderer::CHECKING_LOCAL_SOURCE);
        try {
            $localSource = ($this->localSourceInspector ?? new InspectLocalProjectSourceAction())($module);
            if ($localSource->bootstrapRequired) {
                if ($isDryRun) {
                    $progress->complete(ProjectPublishProgressRenderer::CHECKING_LOCAL_SOURCE);
                    $this->writeBootstrapDryRun($output, $module, $localSource);
                    if ($localSource->stagedChangesOutsideModule) {
                        $output->writeln('The Git index already contains staged changes outside the module.');
                    }
                    return self::SUCCESS;
                }
                if ($localSource->stagedChangesOutsideModule) {
                    $progress->fail(ProjectPublishProgressRenderer::CHECKING_LOCAL_SOURCE);
                    $this->stdErr->writeln('<error>The Git index already contains staged changes outside the module.</error>');
                    $this->stdErr->writeln('Commit or unstage them before project:publish creates the initial module commit.');
                    return self::FAILURE;
                }
                if (!$input->isInteractive()) {
                    $progress->fail(ProjectPublishProgressRenderer::CHECKING_LOCAL_SOURCE);
                    $this->stdErr->writeln('<error>Module source requires an initial Git commit.</error>');
                    $this->stdErr->writeln('Run project:publish interactively or initialize and commit the module first.');
                    return self::FAILURE;
                }
                $this->writeBootstrapPlan($output, $module, $localSource);
                if (!$this->askConfirmation($input, $output, $this->bootstrapQuestion($module, $localSource), true)) {
                    $progress->complete(ProjectPublishProgressRenderer::CHECKING_LOCAL_SOURCE);
                    $output->writeln('Publication cancelled before making remote changes.');
                    return self::SUCCESS;
                }
                [$userName, $userEmail] = $this->bootstrapIdentity($input, $output, $localSource);
                $localSource = ($this->bootstrapSourceAction ?? new BootstrapProjectSourceAction())(
                    $module,
                    $localSource,
                    $userName,
                    $userEmail,
                );
            }
            InspectLocalProjectSourceAction::assertPublishable($localSource);
        } catch (\RuntimeException $exception) {
            $progress->fail(ProjectPublishProgressRenderer::CHECKING_LOCAL_SOURCE);
            $this->stdErr->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::FAILURE;
        }
        $progress->complete(ProjectPublishProgressRenderer::CHECKING_LOCAL_SOURCE);
        if (!$localSource->bootstrapRequired) {
            $this->writeLocalSourceStatus($output, $localSource);
        }

        $progress->start(ProjectPublishProgressRenderer::CHECKING_PROJECT);
        try {
            $projectResult = ($this->projectLookupAction ?? new GetProjectAction($this->client))(
                $module->location->machineName,
            );
            $project = $projectResult->project;
        } catch (\Exception $exception) {
            $progress->fail(ProjectPublishProgressRenderer::CHECKING_PROJECT);
            $this->stdErr->writeln(sprintf(
                '<error>Could not check Drupal.org project: %s</error>',
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }
        $progress->complete(ProjectPublishProgressRenderer::CHECKING_PROJECT);

        $this->writeProjectHeader($output, $module, $project);

        if ($project === null) {
            if ((bool) $input->getOption('dry-run')) {
                $this->writeDryRun($output, $module, null);
                return self::SUCCESS;
            }
            if (!$input->isInteractive()) {
                $this->writeNonInteractiveProjectCreationInstructions($module->location->machineName);
                return self::SUCCESS;
            }
            $review = ($this->reviewWorkflow ?? new ModuleReviewWorkflow($this->client, progress: $progress))->run(
                $input,
                $output,
                $this->questionHelper(),
                $module,
            );
            if (!$review->continuePublishing) {
                return self::SUCCESS;
            }
            $metadata = $this->generateMetadataIfRequested($input, $output, $module, $review, $progress);
            $handoff = ($this->projectCreationHandoff ?? new DrupalOrgProjectCreationHandoff(
                $this->client,
                null,
                null,
                null,
                $progress,
            ))->run(
                $input,
                $output,
                $this->stdErr,
                $this->questionHelper(),
                $module->location->machineName,
                $module->info->name,
                $module->info->type,
                $module->info->coreVersionRequirement,
                $metadata,
            );
            if ($handoff->status === ProjectCreationHandoffResult::FAILED) {
                return self::FAILURE;
            }
            if ($handoff->project === null) {
                return self::SUCCESS;
            }
            $project = $handoff->project;
        }

        try {
            [$version, $branch] = $this->releaseSelection($input, $output);
        } catch (\InvalidArgumentException $exception) {
            $this->stdErr->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::FAILURE;
        }
        if (!$branch->supports($version)) {
            $this->stdErr->writeln(sprintf('<error>Release branch %s does not support version %s.</error>', $branch->name, $version->value));
            return self::FAILURE;
        }

        $progress->register([ProjectPublishProgressRenderer::CHECKING_REPOSITORY => 'Checking Git repository']);
        $progress->start(ProjectPublishProgressRenderer::CHECKING_REPOSITORY);
        $repository = $isDryRun
            ? ($this->repositoryInspector ?? new InspectProjectRepositoryAction($this->repositoryUrls))($module->location->machineName)
            : $this->inspectRepositoryWithRetry($module->location->machineName);
        $progress->complete(ProjectPublishProgressRenderer::CHECKING_REPOSITORY);
        if (!$repository->reachable && $repository->failure !== 'repository_not_ready') {
            $this->stdErr->writeln('<error>Could not inspect Drupal.org repository: ' . ($repository->failure ?? 'network_failure') . '.</error>');
            return self::FAILURE;
        }
        $sourceStatus = null;
        if ($repository->reachable) {
            try {
                $sourceStatus = ($this->sourceInspector ?? new InspectProjectSourceAction($this->repositoryUrls))($module, $branch, $version, $repository, $localSource);
            } catch (\Throwable $exception) {
                $message = $exception->getMessage();
                $label = preg_match('/not a git repository|does not have a commit checked out/i', $message) === 1
                    ? 'Module is not inside a Git repository'
                    : 'Could not inspect Drupal.org remote source';
                $this->stdErr->writeln('<error>' . $label . ': ' . $message . '</error>');
                return self::FAILURE;
            }
        }
        $releaseExists = false;
        if ($repository->reachable) {
            try {
                $releaseExists = $this->releaseExists($project, $version);
            } catch (\Throwable $exception) {
                $this->stdErr->writeln('<error>Could not inspect Drupal.org releases: ' . $exception->getMessage() . '</error>');
                return self::FAILURE;
            }
        }
        $state = $this->buildState($repository, $sourceStatus, $branch, $version, $releaseExists);
        if ($isDryRun) {
            $this->writePublicationDryRun($output, $state);
            return self::SUCCESS;
        }
        return $this->runPublication($input, $output, $progress, $module, $project, $state);
    }

    /**
     */
    private function writeDryRun(
        OutputInterface $output,
        ModuleContext $module,
        ?\mglaman\DrupalOrg\Entity\Project $project,
    ): void {
        $output->writeln('<fg=cyan;options=bold>DRY RUN</>');
        $output->writeln('');
        $this->writeProjectIdentity($output, $module, $project);
        if ($output->isVerbose()) {
            $this->writeVerboseModuleContext($output, $module);
        }
        $output->writeln('');
        $output->writeln('<comment>Plan</comment>');
        foreach ($project === null
            ? ['Project creation is required first.', 'After the project exists, Git/release publication can resume.']
            : ['Inspect repository and resume the first incomplete Git/release step.'] as $index => $plannedAction) {
            $output->writeln(sprintf('  %d. %s', $index + 1, $plannedAction));
        }
        $output->writeln('No changes were made.');
    }

    /** @return array{0: ProjectReleaseVersion, 1: ProjectReleaseBranch} */
    private function releaseSelection(InputInterface $input, OutputInterface $output): array
    {
        $versionValue = $input->getOption('release-version');
        $branchValue = $input->getOption('branch');
        if ((!is_string($versionValue) || $versionValue === '') && !$input->isInteractive()) {
            throw new \InvalidArgumentException('Git/release publication requires --release-version and --branch in non-interactive mode. Example: --release-version=1.0.0 --branch=1.x');
        }
        if (!is_string($versionValue) || $versionValue === '') {
            $question = new \Symfony\Component\Console\Question\Question('Release version [1.0.0]: ', '1.0.0');
            $versionValue = (string) $this->questionHelper()->ask($input, $output, $question);
        }
        $version = ProjectReleaseVersion::fromString($versionValue);
        if (!is_string($branchValue) || $branchValue === '') {
            if (!$input->isInteractive()) {
                throw new \InvalidArgumentException('Git/release publication requires --branch in non-interactive mode.');
            }
            $question = new \Symfony\Component\Console\Question\Question(
                'Release branch [' . $version->suggestedBranch()->name . ']: ',
                $version->suggestedBranch()->name,
            );
            $branchValue = (string) $this->questionHelper()->ask($input, $output, $question);
        }
        return [$version, ProjectReleaseBranch::fromString($branchValue)];
    }

    private function releaseExists(\mglaman\DrupalOrg\Entity\Project $project, ProjectReleaseVersion $version): bool
    {
        foreach (($this->releasesAction ?? new GetProjectReleasesAction($this->client))($project)->releases as $release) {
            if ($release->fieldReleaseVersion === $version->value) {
                return true;
            }
        }
        return false;
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

    private function inspectRepositoryAfterMutation(string $machineName): ProjectRepositoryResult
    {
        $result = $this->inspectRepositoryWithRetry($machineName);
        if (!$result->reachable && $result->failure !== 'repository_not_ready') {
            throw new \RuntimeException(
                'Could not inspect Drupal.org repository after publication: ' . ($result->failure ?? 'network_failure') . '.',
            );
        }
        return $result;
    }

    private function buildState(
        ProjectRepositoryResult $repository,
        ?ProjectSourceStatusResult $source,
        ProjectReleaseBranch $branch,
        ProjectReleaseVersion $version,
        bool $releaseExists,
    ): ProjectPublishState {
        return new ProjectPublishState(
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

    private function runPublication(
        InputInterface $input,
        OutputInterface $output,
        ProjectPublishProgressRenderer $progress,
        ModuleContext $module,
        \mglaman\DrupalOrg\Entity\Project $project,
        ProjectPublishState $state,
    ): int {
        $plan = ProjectPublishPlan::fromState($state);
        $this->writePublicationState($output, $state, $plan);
        if ($plan->nextStep === ProjectPublishStep::WAIT_FOR_REPOSITORY) {
            $output->writeln('Drupal.org project exists. Git repository is not available yet.');
            $output->writeln('Run project:publish again to resume.');
            return self::SUCCESS;
        }
        if ($plan->nextStep === ProjectPublishStep::TAG_CONFLICT) {
            $this->stdErr->writeln(sprintf(
                '<error>Release tag %s already exists for different source code. Release tags are immutable. Use a new release version or check out the source corresponding to %s.</error>',
                $state->version?->value,
                $state->version?->value,
            ));
            return self::FAILURE;
        }
        try {
            if ($plan->nextStep === ProjectPublishStep::PUBLISH_SOURCE) {
                $publisher = $this->sourcePublisher ?? new PublishProjectSourceAction($this->repositoryUrls);
                if ($input->isInteractive()) {
                    $output->writeln(sprintf(
                        "Release branch: %s\n\nSource changes:\n  Added:     %d\n  Modified:  %d\n  Deleted:   %d\n",
                        $state->branch?->name,
                        $state->filesAdded,
                        $state->filesModified,
                        $state->filesDeleted,
                    ));
                    if (!$this->askConfirmation($input, $output, 'Publish these changes to Drupal.org? [Y/n] ', true)) {
                        return self::SUCCESS;
                    }
                }
                $progress->register([ProjectPublishProgressRenderer::PUBLISHING_SOURCE => 'Publishing source']);
                $progress->start(ProjectPublishProgressRenderer::PUBLISHING_SOURCE);
                $source = $publisher($module, $module->location->machineName, $state->branch, $state->version, $this->sourceStatusFromState($state));
                $progress->complete(ProjectPublishProgressRenderer::PUBLISHING_SOURCE);
                $output->writeln($source->alreadyUpToDate ? 'Source already published.' : 'Source published to ' . $source->branch . '.');
                $repository = $this->inspectRepositoryAfterMutation($module->location->machineName);
                if (!$repository->reachable) {
                    $state = $this->buildState($repository, null, $state->branch, $state->version, $state->releaseExists);
                    return $this->runPublication($input, $output, $progress, $module, $project, $state);
                }
                $sourceStatus = ($this->sourceInspector ?? new InspectProjectSourceAction($this->repositoryUrls))($module, $state->branch, $state->version, $repository);
                $state = $this->buildState($repository, $sourceStatus, $state->branch, $state->version, $state->releaseExists);
                return $this->runPublication($input, $output, $progress, $module, $project, $state);
            }
            if ($plan->nextStep === ProjectPublishStep::CREATE_TAG) {
                $expectedSource = $this->sourceStatusFromState($state);
                $this->assertCurrentLocalSource($module, $expectedSource);
                if ($input->isInteractive() && !$this->askConfirmation(
                    $input,
                    $output,
                    sprintf(
                        'Release branch: %s%sVersion:        %s%sTree:           %s%sCreate and push release tag %s? [Y/n] ',
                        $state->branch->name,
                        "\n",
                        $state->version->value,
                        "\n",
                        substr((string) $state->localTreeSha, 0, 7),
                        "\n",
                        $state->version->value
                    ),
                    true,
                )) {
                    $output->writeln('Release tag was not created. Run project:publish again to resume.');
                    return self::SUCCESS;
                }
                $progress->register([ProjectPublishProgressRenderer::CREATING_TAG => 'Creating release tag']);
                $progress->start(ProjectPublishProgressRenderer::CREATING_TAG);
                $tag = ($this->tagCreator ?? new CreateProjectTagAction($this->repositoryUrls))($module->location->machineName, $state->branch, $state->version, $expectedSource);
                $progress->complete(ProjectPublishProgressRenderer::CREATING_TAG);
                $output->writeln('Tag ' . $tag->tag . ($tag->created ? ' published.' : ' already exists.'));
                $repository = $this->inspectRepositoryAfterMutation($module->location->machineName);
                if (!$repository->reachable) {
                    $state = $this->buildState($repository, null, $state->branch, $state->version, $state->releaseExists);
                    return $this->runPublication($input, $output, $progress, $module, $project, $state);
                }
                $sourceStatus = ($this->sourceInspector ?? new InspectProjectSourceAction($this->repositoryUrls))($module, $state->branch, $state->version, $repository);
                $state = $this->buildState($repository, $sourceStatus, $state->branch, $state->version, $state->releaseExists);
                return $this->runPublication($input, $output, $progress, $module, $project, $state);
            }
            if ($plan->nextStep === ProjectPublishStep::CREATE_RELEASE) {
                if (!$input->isInteractive()) {
                    $output->writeln('Create the Drupal.org release manually, then run project:publish again to verify it.');
                    $output->writeln('https://www.drupal.org/node/add/project-release/' . $project->nid);
                    return self::SUCCESS;
                }
                $progress->register([ProjectPublishProgressRenderer::PREPARING_RELEASE => 'Preparing Drupal.org release']);
                $progress->start(ProjectPublishProgressRenderer::PREPARING_RELEASE);
                $found = ($this->releaseHandoff ?? new DrupalOrgReleaseCreationHandoff($this->client))->run(
                    $input,
                    $output,
                    $this->stdErr,
                    $this->questionHelper(),
                    $project,
                    $state->version,
                );
                $progress->complete(ProjectPublishProgressRenderer::PREPARING_RELEASE);
                if (!$found) {
                    $output->writeln('Run project:publish again to resume release verification.');
                    return self::SUCCESS;
                }
                $state = new ProjectPublishState(
                    $state->repositoryReachable,
                    $state->branch,
                    $state->version,
                    $state->localSourceCommitSha,
                    $state->localTreeSha,
                    $state->branchExists,
                    $state->branchTreeSha,
                    $state->branchMatchesSource,
                    $state->tagExists,
                    $state->tagTreeSha,
                    $state->tagMatchesSource,
                    true,
                    false,
                    $state->filesAdded,
                    $state->filesModified,
                    $state->filesDeleted,
                    $state->branchCommitSha,
                    $state->tagCommitSha,
                );
                return $this->runPublication($input, $output, $progress, $module, $project, $state);
            }
            if ($plan->nextStep === ProjectPublishStep::VERIFY_COMPOSER) {
                $progress->register([ProjectPublishProgressRenderer::VERIFYING_COMPOSER => 'Verifying Composer package']);
                $progress->start(ProjectPublishProgressRenderer::VERIFYING_COMPOSER);
                $composer = ($this->composerVerifier ?? new VerifyComposerPackageAction())($module->location->machineName, $state->version);
                $progress->complete(ProjectPublishProgressRenderer::VERIFYING_COMPOSER);
                if ($composer->status === ComposerPackageResult::COMPOSER_UNAVAILABLE || $composer->status === ComposerPackageResult::NETWORK_OR_REPOSITORY_FAILURE) {
                    throw new \RuntimeException('Could not verify Composer metadata: ' . $composer->status . '.');
                }
                if (!$composer->available()) {
                    $output->writeln('Composer metadata is still propagating. Run project:publish again to verify.');
                    return self::SUCCESS;
                }
                $state = $this->withComposerVisible($state);
            }
            $this->writeComplete($output, $module->location->machineName, $state->branch, $state->version);
            return self::SUCCESS;
        } catch (\RuntimeException $exception) {
            $progress->failActive();
            $this->stdErr->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::FAILURE;
        }
    }

    private function sourceStatusFromState(ProjectPublishState $state): ProjectSourceStatusResult
    {
        if ($state->localSourceCommitSha === null || $state->localTreeSha === null) {
            throw new \LogicException('Local source status is unavailable; inspect the repository again before publishing.');
        }
        return new ProjectSourceStatusResult(
            $state->localSourceCommitSha,
            $state->localTreeSha,
            $state->branchExists,
            $state->branchTreeSha,
            $state->branchMatchesSource,
            $state->tagExists,
            $state->tagTreeSha,
            $state->tagMatchesSource,
            $state->filesAdded,
            $state->filesModified,
            $state->filesDeleted,
            $state->branchCommitSha,
            $state->tagCommitSha,
        );
    }

    private function assertCurrentLocalSource(ModuleContext $module, ProjectSourceStatusResult $expected): void
    {
        try {
            $local = ($this->localSourceInspector ?? new InspectLocalProjectSourceAction())($module);
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException("Module source changed during publication.\n\nRun project:publish again to review and publish the current committed source.", 0, $exception);
        }
        if ($local->sourceCommitSha !== $expected->localSourceCommitSha || $local->sourceTreeSha !== $expected->localTreeSha) {
            throw new \RuntimeException("Module source changed during publication.\n\nRun project:publish again to review and publish the current committed source.");
        }
    }

    private function withComposerVisible(ProjectPublishState $state): ProjectPublishState
    {
        return new ProjectPublishState(
            $state->repositoryReachable,
            $state->branch,
            $state->version,
            $state->localSourceCommitSha,
            $state->localTreeSha,
            $state->branchExists,
            $state->branchTreeSha,
            $state->branchMatchesSource,
            $state->tagExists,
            $state->tagTreeSha,
            $state->tagMatchesSource,
            $state->releaseExists,
            true,
            $state->filesAdded,
            $state->filesModified,
            $state->filesDeleted,
            $state->branchCommitSha,
            $state->tagCommitSha,
        );
    }

    private function writePublicationState(OutputInterface $output, ProjectPublishState $state, ProjectPublishPlan $plan): void
    {
        $output->writeln('Drupal.org project ............... ✓');
        $output->writeln('Repository available ............. ' . ($state->repositoryReachable ? '✓' : '□'));
        $output->writeln('Release branch ' . $state->branch?->name . ' ............... ' . ($state->branchMatchesSource ? '✓' : '□'));
        $output->writeln('Tag ' . $state->version?->value . ' ........................ ' . ($state->tagMatchesSource ? '✓' : '□'));
        $output->writeln('Drupal.org release ............... ' . ($state->releaseExists ? '✓' : '□'));
        $output->writeln('Composer package ................. ' . ($state->composerPackageVisible ? '✓' : '□'));
        $output->writeln('Resuming from: ' . $plan->nextStep->value);
    }

    private function writePublicationDryRun(OutputInterface $output, ProjectPublishState $state): void
    {
        $plan = ProjectPublishPlan::fromState($state);
        $output->writeln('<fg=cyan;options=bold>DRY RUN</>');
        $this->writePublicationState($output, $state, $plan);
        $output->writeln('');
        $output->writeln('<comment>Plan</comment>');
        $steps = match ($plan->nextStep) {
            ProjectPublishStep::WAIT_FOR_REPOSITORY => ['Wait for Drupal.org Git repository availability.'],
            ProjectPublishStep::TAG_CONFLICT => ['Stop: the existing release tag points to different source content.'],
            ProjectPublishStep::PUBLISH_SOURCE => ['Publish module source to ' . $state->branch?->name, 'Create tag ' . $state->version?->value, 'Open Drupal.org release form', 'Verify Drupal.org release', 'Verify Composer package'],
            ProjectPublishStep::CREATE_TAG => ['Create tag ' . $state->version?->value, 'Open Drupal.org release form', 'Verify Drupal.org release', 'Verify Composer package'],
            ProjectPublishStep::CREATE_RELEASE => ['Open Drupal.org release form', 'Verify Drupal.org release', 'Verify Composer package'],
            ProjectPublishStep::VERIFY_COMPOSER => ['Verify drupal package through Composer'],
            default => ['No changes; project is fully published.'],
        };
        foreach ($steps as $index => $step) {
            $output->writeln('  ' . ($index + 1) . '. ' . $step);
        }
        $output->writeln('No changes were made.');
    }

    private function writeComplete(OutputInterface $output, string $machineName, ProjectReleaseBranch $branch, ProjectReleaseVersion $version): void
    {
        $output->writeln('✓ Module validated');
        $output->writeln('✓ Drupal.org project exists');
        $output->writeln('✓ Source published to ' . $branch->name);
        $output->writeln('✓ Tag ' . $version->value . ' published');
        $output->writeln('✓ Drupal.org release ' . $version->value . ' available');
        $output->writeln('✓ Composer package available');
        $output->writeln('');
        $output->writeln('Published successfully.');
        $constraint = $version->isStable()
            ? '^' . $version->major . '.' . $version->minor
            : $version->value;
        $output->writeln('composer require drupal/' . $machineName . ':' . $constraint);
    }

    /**
     */
    private function generateMetadataIfRequested(
        InputInterface $input,
        OutputInterface $output,
        ModuleContext $module,
        ModuleReviewWorkflowResult $review,
        ProjectPublishProgressRenderer $progress,
    ): ?ProjectMetadataResult {
        $analysis = $review->analysisResult;
        if ($review->runtime === null || $analysis === null || $analysis->review === null) {
            return null;
        }

        $output->writeln('');
        $output->writeln('This uses one additional AI request. Module source is not sent again.');
        $output->writeln('');
        if (!$this->askConfirmation(
            $input,
            $output,
            '<fg=yellow;options=bold>Generate Drupal.org project metadata automatically?</> [Y/n] ',
            true,
        )) {
            return null;
        }

        $progress->register([
            ProjectPublishProgressRenderer::GENERATING_METADATA => 'Generating project metadata',
        ]);
        $progress->start(ProjectPublishProgressRenderer::GENERATING_METADATA);
        try {
            $metadata = ($this->metadataAction ?? new GenerateProjectMetadataAction())(
                $review->runtime,
                $module->location->machineName,
                $module->info->name,
                $module->info->type,
                $module->info->coreVersionRequirement,
                $module->info->dependencies,
                $analysis->review,
                $review->sourceCoverage,
                $progress->tickCallback(),
            );
            $progress->complete(ProjectPublishProgressRenderer::GENERATING_METADATA);

            return $metadata;
        } catch (\Exception) {
            $progress->fail(ProjectPublishProgressRenderer::GENERATING_METADATA);
            $progress->warning('Could not generate Drupal.org project metadata automatically.');
            $progress->warning('You can enter the project metadata manually on Drupal.org.');

            return null;
        }
    }

    /**
     */
    private function writeProjectHeader(
        OutputInterface $output,
        ModuleContext $module,
        ?\mglaman\DrupalOrg\Entity\Project $project,
    ): void {
        $output->writeln('');
        $output->writeln('<fg=cyan;options=bold>PROJECT PUBLISH</>');
        $output->writeln('----------------------------------------');
        $output->writeln('');
        $this->writeProjectIdentity($output, $module, $project);
        if ($output->isVerbose()) {
            $this->writeVerboseModuleContext($output, $module);
        }
        $output->writeln('');
    }

    /**
     */
    private function writeProjectIdentity(
        OutputInterface $output,
        ModuleContext $module,
        ?\mglaman\DrupalOrg\Entity\Project $project,
    ): void {
        $state = $project === null
            ? '<fg=yellow;options=bold>NOT PUBLISHED</>'
            : '<fg=green;options=bold>PROJECT EXISTS</>';
        $output->writeln(sprintf('%s (%s)', $module->info->name, $module->location->machineName));
        $output->writeln('Core ' . $module->info->coreVersionRequirement . ' · Drupal.org: ' . $state);
    }

    /**
     */
    private function writeVerboseModuleContext(
        OutputInterface $output,
        ModuleContext $module,
    ): void {
        $output->writeln('');
        $output->writeln('<comment>Module details</comment>');
        $output->writeln('Module path: ' . $module->location->resolvedPath);
        $output->writeln('Info file: ' . $module->location->infoFile);
        if ($module->location->projectRoot !== null) {
            $output->writeln('Project root: ' . $module->location->projectRoot);
        }
        $output->writeln('Module type: ' . $module->info->type);
    }

    private function writeNonInteractiveProjectCreationInstructions(string $machineName): void
    {
        $this->stdOut->writeln('Drupal.org project does not exist: ' . $machineName);
        $this->stdOut->writeln('');
        $this->stdOut->writeln('Create it manually:');
        $this->stdOut->writeln(DrupalOrgProjectCreationHandoff::PROJECT_CREATION_URL);
        $this->stdOut->writeln('');
        $this->stdOut->writeln('Machine name: ' . $machineName);
    }

    private function writeBootstrapPlan(
        OutputInterface $output,
        ModuleContext $module,
        ProjectLocalSourceResult $source,
    ): void {
        $output->writeln('');
        $output->writeln('<comment>INITIAL GIT COMMIT</comment>');
        $output->writeln('');
        if (!$source->repositoryExists) {
            $output->writeln('The module is not tracked by Git yet.');
        } elseif (!$source->repositoryHasHead) {
            $output->writeln('The Git repository has no commits yet.');
        } else {
            $output->writeln('Module ' . $module->location->machineName . ' has not been committed yet.');
        }
        $output->writeln('');
        $output->writeln('Repository:');
        $output->writeln('  ' . ($source->repositoryExists ? $source->gitRoot : $module->location->resolvedPath));
        $output->writeln('Module:');
        $output->writeln('  ' . ($source->modulePrefix === '' ? '.' : rtrim($source->modulePrefix, '/')));
        if (count($source->candidateFiles) > 20 && !$output->isVerbose()) {
            $output->writeln('Files: ' . count($source->candidateFiles));
        } else {
            $output->writeln('Files:');
            foreach ($source->candidateFiles as $file) {
                $relative = $source->modulePrefix !== '' && str_starts_with($file, $source->modulePrefix)
                    ? ltrim(substr($file, strlen($source->modulePrefix)), '/')
                    : $file;
                $output->writeln('  ' . $relative);
            }
        }
        $output->writeln('Only files inside the module will be staged.');
        $output->writeln('');
    }

    private function writeLocalSourceStatus(OutputInterface $output, ProjectLocalSourceResult $source): void
    {
        $output->writeln('Local Git repository ............. ' . ($source->repositoryExists ? '✓' : 'not initialized'));
        $output->writeln('Committed module source .......... ' . ($source->moduleExistsInHead && $source->repositoryHasHead ? '✓' : '□'));
        $output->writeln('Local mutation ................... ' . ($source->moduleClean ? 'none' : 'uncommitted changes'));
    }

    private function writeBootstrapDryRun(
        OutputInterface $output,
        ModuleContext $module,
        ProjectLocalSourceResult $source,
    ): void {
        $output->writeln('<fg=cyan;options=bold>DRY RUN</>');
        $output->writeln('');
        $output->writeln('Local Git repository ............. ' . ($source->repositoryExists ? 'initialized' : 'not initialized'));
        $output->writeln('Initial module commit ............ required');
        $output->writeln('');
        $output->writeln('<comment>Plan</comment>');
        $output->writeln('1. Initialize Git in ' . ($source->repositoryExists ? $source->gitRoot : $module->location->resolvedPath));
        $output->writeln('2. Stage ' . count($source->candidateFiles) . ' module files');
        $output->writeln('3. Create initial ' . $module->location->machineName . ' implementation commit');
        $output->writeln('No changes were made.');
    }

    private function bootstrapQuestion(ModuleContext $module, ProjectLocalSourceResult $source): string
    {
        if (!$source->repositoryExists) {
            return 'Initialize a Git repository for this module and create its initial commit? [Y/n] ';
        }
        if (!$source->repositoryHasHead) {
            return 'Create the initial commit containing module ' . $module->location->machineName . '? [Y/n] ';
        }

        return 'Module ' . $module->location->machineName . ' has not been committed yet. Create its initial module commit? [Y/n] ';
    }

    /** @return array{?string, ?string} */
    private function bootstrapIdentity(
        InputInterface $input,
        OutputInterface $output,
        ProjectLocalSourceResult $source,
    ): array {
        $name = $source->gitUserName;
        $email = $source->gitUserEmail;
        if ($name === null || $name === '') {
            $name = (string) $this->questionHelper()->ask($input, $output, new Question('Git user name: '));
        }
        if ($email === null || $email === '') {
            $email = (string) $this->questionHelper()->ask($input, $output, new Question('Git email: '));
        }
        $name = trim($name);
        $email = trim($email);

        return [$name !== '' ? $name : null, $email !== '' ? $email : null];
    }

    private function askConfirmation(
        InputInterface $input,
        OutputInterface $output,
        string $question,
        bool $default,
    ): bool {
        return (bool) $this->questionHelper()->ask(
            $input,
            $output,
            new ConfirmationQuestion($question, $default),
        );
    }

    private function questionHelper(): QuestionHelper
    {
        try {
            $helper = $this->getHelper('question');
        } catch (\LogicException) {
            return new QuestionHelper();
        }
        if (!$helper instanceof QuestionHelper) {
            return new QuestionHelper();
        }

        return $helper;
    }
}
