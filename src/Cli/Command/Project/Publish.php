<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Action\Project\GenerateProjectMetadataAction;
use mglaman\DrupalOrg\Project\ComposerContextLocator;
use mglaman\DrupalOrg\Project\ModuleInfoReader;
use mglaman\DrupalOrg\Project\ModuleLocator;
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
     */
    public function __construct(
        private readonly ?ModuleReviewWorkflow $reviewWorkflow = null,
        private readonly ?GenerateProjectMetadataAction $metadataAction = null,
        private readonly ?DrupalOrgProjectCreationHandoff $projectCreationHandoff = null,
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
            $module = (new ModuleLocator(new ComposerContextLocator()))->locate($modulePath);
            $moduleInfo = (new ModuleInfoReader())->read($module['infoFile'], $module['moduleName']);
        } catch (\RuntimeException $exception) {
            $progress->fail(ProjectPublishProgressRenderer::PREPARING_MODULE);
            $this->stdErr->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return self::FAILURE;
        }
        $progress->complete(ProjectPublishProgressRenderer::PREPARING_MODULE);

        $progress->start(ProjectPublishProgressRenderer::CHECKING_PROJECT);
        try {
            $project = $this->client->getProject($module['moduleName']);
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
            $this->writeDryRun($output, $module, $moduleInfo, $project);

            return self::SUCCESS;
        }

        $this->writeProjectHeader($output, $module, $moduleInfo, $project);

        if ($project !== null) {
            $output->writeln('Drupal.org project already exists.');
            $output->writeln('<comment>Repository and release publication are not implemented yet.</comment>');
            return self::SUCCESS;
        }

        if (!$input->isInteractive()) {
            $this->writeNonInteractiveProjectCreationInstructions($module['moduleName']);

            return self::SUCCESS;
        }

        $review = ($this->reviewWorkflow ?? new ModuleReviewWorkflow($this->client, progress: $progress))->run(
            $input,
            $output,
            $this->questionHelper(),
            $module,
            $moduleInfo,
        );
        if (!$review->continuePublishing) {
            return self::SUCCESS;
        }

        $metadata = $this->generateMetadataIfRequested($input, $output, $module, $moduleInfo, $review, $progress);
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
            $module['moduleName'],
            $moduleInfo['name'],
            $moduleInfo['type'],
            $moduleInfo['coreVersionRequirement'],
            $metadata,
        );

        return $handoff->status === ProjectCreationHandoffResult::FAILED
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param array{resolvedPath: string, moduleName: string, infoFile: string, projectRoot: ?string, webRoot: ?string} $module
     * @param array{name: string, type: string, coreVersionRequirement: string, dependencies: list<string>} $moduleInfo
     */
    private function writeDryRun(
        OutputInterface $output,
        array $module,
        array $moduleInfo,
        ?\mglaman\DrupalOrg\Entity\Project $project,
    ): void {
        $output->writeln('<fg=cyan;options=bold>DRY RUN</>');
        $output->writeln('');
        $this->writeProjectIdentity($output, $module, $moduleInfo, $project);
        if ($output->isVerbose()) {
            $this->writeVerboseModuleContext($output, $module, $moduleInfo);
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
                sprintf('Use the existing Drupal.org project "%s".', $module['moduleName']),
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
     * @param array{resolvedPath: string, moduleName: string, infoFile: string, projectRoot: ?string, webRoot: ?string} $module
     * @param array{name: string, type: string, coreVersionRequirement: string, dependencies: list<string>} $moduleInfo
     */
    private function generateMetadataIfRequested(
        InputInterface $input,
        OutputInterface $output,
        array $module,
        array $moduleInfo,
        ModuleReviewWorkflowResult $review,
        ProjectPublishProgressRenderer $progress,
    ): ?ProjectMetadataResult {
        $analysis = $review->analysisResult;
        if ($review->runtime === null || !is_array($analysis) || !is_array($analysis['review'] ?? null)) {
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
            /** @var array{
             *     review: array{
             *         purpose: string,
             *         capabilities: list<string>,
             *         drupal_concepts: list<string>,
             *         integrations: list<string>,
             *         reusability: array{status: string}
             *     }
             * } $analysis */
            $metadata = ($this->metadataAction ?? new GenerateProjectMetadataAction())(
                $review->runtime,
                $module['moduleName'],
                $moduleInfo['name'],
                $moduleInfo['type'],
                $moduleInfo['coreVersionRequirement'],
                $moduleInfo['dependencies'],
                $analysis['review'],
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
     * @param array{resolvedPath: string, moduleName: string, infoFile: string, projectRoot: ?string, webRoot: ?string} $module
     * @param array{name: string, type: string, coreVersionRequirement: string, dependencies: list<string>} $moduleInfo
     */
    private function writeProjectHeader(
        OutputInterface $output,
        array $module,
        array $moduleInfo,
        ?\mglaman\DrupalOrg\Entity\Project $project,
    ): void {
        $output->writeln('');
        $output->writeln('<fg=cyan;options=bold>PROJECT PUBLISH</>');
        $output->writeln('----------------------------------------');
        $output->writeln('');
        $this->writeProjectIdentity($output, $module, $moduleInfo, $project);
        if ($output->isVerbose()) {
            $this->writeVerboseModuleContext($output, $module, $moduleInfo);
        }
        $output->writeln('');
    }

    /**
     * @param array{resolvedPath: string, moduleName: string, infoFile: string, projectRoot: ?string, webRoot: ?string} $module
     * @param array{name: string, type: string, coreVersionRequirement: string, dependencies: list<string>} $moduleInfo
     */
    private function writeProjectIdentity(
        OutputInterface $output,
        array $module,
        array $moduleInfo,
        ?\mglaman\DrupalOrg\Entity\Project $project,
    ): void {
        $state = $project === null
            ? '<fg=yellow;options=bold>NOT PUBLISHED</>'
            : '<fg=green;options=bold>PROJECT EXISTS</>';
        $output->writeln(sprintf('%s (%s)', $moduleInfo['name'], $module['moduleName']));
        $output->writeln('Core ' . $moduleInfo['coreVersionRequirement'] . ' · Drupal.org: ' . $state);
    }

    /**
     * @param array{resolvedPath: string, moduleName: string, infoFile: string, projectRoot: ?string, webRoot: ?string} $module
     * @param array{name: string, type: string, coreVersionRequirement: string, dependencies: list<string>} $moduleInfo
     */
    private function writeVerboseModuleContext(
        OutputInterface $output,
        array $module,
        array $moduleInfo,
    ): void {
        $output->writeln('');
        $output->writeln('<comment>Module details</comment>');
        $output->writeln('Module path: ' . $module['resolvedPath']);
        $output->writeln('Info file: ' . $module['infoFile']);
        if ($module['projectRoot'] !== null) {
            $output->writeln('Project root: ' . $module['projectRoot']);
        }
        $output->writeln('Module type: ' . $moduleInfo['type']);
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
