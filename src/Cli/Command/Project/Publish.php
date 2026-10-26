<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Action\Project\GenerateProjectMetadataAction;
use mglaman\DrupalOrg\Action\Project\GetProjectAction;
use mglaman\DrupalOrg\Action\Project\ResolveModuleAction;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ModuleInfoReader;
use mglaman\DrupalOrg\Project\ModuleLocator;
use mglaman\DrupalOrg\Project\ComposerContextLocator;
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

        if ((bool) $input->getOption('dry-run')) {
            $this->writeDryRun($output, $module, $project);

            return self::SUCCESS;
        }

        $this->writeProjectHeader($output, $module, $project);

        if ($project !== null) {
            $output->writeln('Drupal.org project already exists.');
            $output->writeln('<comment>Repository and release publication are not implemented yet.</comment>');
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

        return $handoff->status === ProjectCreationHandoffResult::FAILED
            ? self::FAILURE
            : self::SUCCESS;
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
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            throw new \LogicException('The question helper is not available.');
        }

        return $helper;
    }
}
