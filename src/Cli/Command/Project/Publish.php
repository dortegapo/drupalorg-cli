<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Action\Project\GenerateProjectMetadataAction;
use mglaman\DrupalOrg\Action\Project\GetProjectAction;
use mglaman\DrupalOrg\Action\Project\GetProjectReleasesAction;
use mglaman\DrupalOrg\Action\Project\InspectProjectRepositoryAction;
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
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

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
            ->addOption('version', null, InputOption::VALUE_REQUIRED, 'Release version, for example 1.0.0.')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Release branch, for example 1.x.')
            ->setDescription('Publishes a project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modulePath = (string) $input->getArgument('module');
        $progress = new ProjectPublishProgressRenderer($output);
        $progress->register([
            ProjectPublishProgressRenderer::PREPARING_MODULE => 'Preparing module',
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

        $progress->register([ProjectPublishProgressRenderer::CHECKING_REPOSITORY => 'Checking Git repository']);
        $progress->start(ProjectPublishProgressRenderer::CHECKING_REPOSITORY);
        $repository = (new InspectProjectRepositoryAction())($module->location->machineName);
        $progress->complete(ProjectPublishProgressRenderer::CHECKING_REPOSITORY);
        $releaseExists = $repository->reachable && $this->releaseExists($project, $version);
        $state = new ProjectPublishState(
            true,
            true,
            $repository->reachable,
            $branch,
            $version,
            $repository->branchSha($branch->name),
            $repository->tagSha($version->value),
            $repository->branchSha($branch->name) !== null,
            $repository->tagSha($version->value) !== null,
            $releaseExists,
        );
        if ((bool) $input->getOption('dry-run')) {
            $this->writePublicationDryRun($output, $state);
            return self::SUCCESS;
        }
        return $this->runPublication($input, $output, $progress, $module, $project, $state, $repository);
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

        $detailedActions = $project === null
            ? [
                'Offer an optional AI-assisted contribution review and local PDF report.',
                'Optionally generate Drupal.org project metadata from the AI module characterization.',
                'Open the Drupal.org project creation page for manual project creation.',
                'Verify that the Drupal.org project exists.',
                'Stop after the project creation handoff and verification.',
            ]
            : [
                sprintf('Use the existing Drupal.org project "%s".', $module->location->machineName),
                'Report that repository and release publication are not implemented.',
            ];

        $plannedActions = $output->isVerbose()
            ? $detailedActions
            : ($project === null
                ? [
                    'Optional contribution review',
                    'Optional Drupal.org metadata generation',
                    'Manual Drupal.org project creation',
                    'Verify project',
                    'Stop after project verification',
                ]
                : [
                    'Report unsupported repository and release stages',
                ]);

        foreach ($plannedActions as $index => $plannedAction) {
            $output->writeln(sprintf('  %d. %s', $index + 1, $plannedAction));
        }

        $output->writeln('');
        if ($output->isVerbose()) {
            $output->writeln('Publication execution is not implemented yet.');
        }
        $output->writeln('No changes were made.');
    }

    /** @return array{0: ProjectReleaseVersion, 1: ProjectReleaseBranch} */
    private function releaseSelection(InputInterface $input, OutputInterface $output): array
    {
        $versionValue = $input->getOption('version');
        $branchValue = $input->getOption('branch');
        if ((!is_string($versionValue) || $versionValue === '') && !$input->isInteractive()) {
            throw new \InvalidArgumentException('Git/release publication requires --version and --branch in non-interactive mode. Example: --version=1.0.0 --branch=1.x');
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
        foreach ((new GetProjectReleasesAction($this->client))($project)->releases as $release) {
            if ($release->fieldReleaseVersion === $version->value) {
                return true;
            }
        }
        return false;
    }

    private function runPublication(
        InputInterface $input,
        OutputInterface $output,
        ProjectPublishProgressRenderer $progress,
        ModuleContext $module,
        \mglaman\DrupalOrg\Entity\Project $project,
        ProjectPublishState $state,
        \mglaman\DrupalOrg\Result\Project\ProjectRepositoryResult $repository,
    ): int {
        $plan = ProjectPublishPlan::fromState($state);
        $this->writePublicationState($output, $state, $plan);
        if ($plan->nextStep === ProjectPublishStep::WAIT_FOR_REPOSITORY) {
            $output->writeln('Drupal.org project exists. Git repository is not available yet.');
            $output->writeln('Run project:publish again to resume.');
            return self::SUCCESS;
        }
        try {
            if ($plan->nextStep === ProjectPublishStep::PUBLISH_SOURCE) {
                $progress->register([ProjectPublishProgressRenderer::PUBLISHING_SOURCE => 'Publishing source']);
                $progress->start(ProjectPublishProgressRenderer::PUBLISHING_SOURCE);
                $source = (new PublishProjectSourceAction())($module, $module->location->machineName, $state->branch, $state->version);
                $progress->complete(ProjectPublishProgressRenderer::PUBLISHING_SOURCE);
                $output->writeln($source->alreadyUpToDate ? 'Source already published.' : 'Source published to ' . $source->branch . '.');
                $repository = (new InspectProjectRepositoryAction())($module->location->machineName);
                $state = new ProjectPublishState(
                    true,
                    true,
                    true,
                    $state->branch,
                    $state->version,
                    $repository->branchSha($state->branch->name),
                    $repository->tagSha($state->version->value),
                    true,
                    $repository->tagSha($state->version->value) !== null,
                    $state->releaseExists
                );
                return $this->runPublication($input, $output, $progress, $module, $project, $state, $repository);
            }
            if ($plan->nextStep === ProjectPublishStep::CREATE_TAG) {
                if ($input->isInteractive() && !$this->askConfirmation(
                    $input,
                    $output,
                    sprintf(
                        'Release branch: %s%sVersion:        %s%sCommit:         %s%sCreate and push release tag %s? [Y/n] ',
                        $state->branch->name,
                        "\n",
                        $state->version->value,
                        "\n",
                        substr((string) $state->remoteBranchSha, 0, 7),
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
                $tag = (new CreateProjectTagAction())($module->location->machineName, $state->branch, $state->version, $repository);
                $progress->complete(ProjectPublishProgressRenderer::CREATING_TAG);
                $output->writeln('Tag ' . $tag->tag . ($tag->created ? ' published.' : ' already exists.'));
                $repository = (new InspectProjectRepositoryAction())($module->location->machineName);
                $state = new ProjectPublishState(
                    true,
                    true,
                    true,
                    $state->branch,
                    $state->version,
                    $repository->branchSha($state->branch->name),
                    $repository->tagSha($state->version->value),
                    true,
                    true,
                    $state->releaseExists
                );
                return $this->runPublication($input, $output, $progress, $module, $project, $state, $repository);
            }
            if ($plan->nextStep === ProjectPublishStep::CREATE_RELEASE) {
                if (!$input->isInteractive()) {
                    $output->writeln('Create the Drupal.org release manually, then run project:publish again to verify it.');
                    $output->writeln('https://www.drupal.org/node/add/project-release/' . $project->nid);
                    return self::SUCCESS;
                }
                $progress->register([ProjectPublishProgressRenderer::PREPARING_RELEASE => 'Preparing Drupal.org release']);
                $progress->start(ProjectPublishProgressRenderer::PREPARING_RELEASE);
                $found = (new DrupalOrgReleaseCreationHandoff($this->client))->run(
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
                    true,
                    true,
                    true,
                    $state->branch,
                    $state->version,
                    $state->remoteBranchSha,
                    $state->remoteTagSha,
                    true,
                    true,
                    true
                );
                return $this->runPublication($input, $output, $progress, $module, $project, $state, $repository);
            }
            if ($plan->nextStep === ProjectPublishStep::VERIFY_COMPOSER) {
                $progress->register([ProjectPublishProgressRenderer::VERIFYING_COMPOSER => 'Verifying Composer package']);
                $progress->start(ProjectPublishProgressRenderer::VERIFYING_COMPOSER);
                $composer = (new VerifyComposerPackageAction())($module->location->machineName, $state->version);
                $progress->complete(ProjectPublishProgressRenderer::VERIFYING_COMPOSER);
                if (!$composer->available) {
                    $output->writeln('Composer metadata is still propagating. Run project:publish again to verify.');
                    return self::SUCCESS;
                }
                $state = new ProjectPublishState(
                    true,
                    true,
                    true,
                    $state->branch,
                    $state->version,
                    $state->remoteBranchSha,
                    $state->remoteTagSha,
                    true,
                    true,
                    true,
                    null,
                    true
                );
            }
            $this->writeComplete($output, $module->location->machineName, $state->version);
            return self::SUCCESS;
        } catch (\RuntimeException $exception) {
            $progress->failActive();
            $this->stdErr->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::FAILURE;
        }
    }

    private function writePublicationState(OutputInterface $output, ProjectPublishState $state, ProjectPublishPlan $plan): void
    {
        $output->writeln('Drupal.org project ............... ✓');
        $output->writeln('Repository available ............. ' . ($state->repositoryReachable ? '✓' : '□'));
        $output->writeln('Release branch ' . $state->branch?->name . ' ............... ' . ($state->sourcePublished ? '✓' : '□'));
        $output->writeln('Tag ' . $state->version?->value . ' ........................ ' . ($state->tagExists ? '✓' : '□'));
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

    private function writeComplete(OutputInterface $output, string $machineName, ProjectReleaseVersion $version): void
    {
        $output->writeln('✓ Module validated');
        $output->writeln('✓ Drupal.org project exists');
        $output->writeln('✓ Source published to ' . $version->suggestedBranch()->name);
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
