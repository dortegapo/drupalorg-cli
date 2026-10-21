<?php

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

        try {
            $module = (new ModuleLocator(new ComposerContextLocator()))->locate($modulePath);
            $moduleInfo = (new ModuleInfoReader())->read($module['infoFile'], $module['moduleName']);
        } catch (\RuntimeException $exception) {
            $this->stdErr->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return self::FAILURE;
        }

        try {
            $project = $this->client->getProject($module['moduleName']);
        } catch (\Exception $exception) {
            $this->stdErr->writeln(sprintf(
                '<error>Could not check Drupal.org project: %s</error>',
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        if ((bool) $input->getOption('dry-run')) {
            $this->writeDryRun($module, $moduleInfo, $project);

            return self::SUCCESS;
        }

        $this->stdOut->writeln('');
        $this->stdOut->writeln('Module');
        $this->stdOut->writeln('');
        $this->writeModuleContext($module, $moduleInfo);
        $this->stdOut->writeln('');
        $this->stdOut->writeln(sprintf(
            'Drupal.org project %s: %s',
            $project === null ? 'does not exist' : 'exists',
            $module['moduleName'],
        ));

        if ($project !== null) {
            return self::SUCCESS;
        }

        if (!$input->isInteractive()) {
            $this->writeNonInteractiveProjectCreationInstructions($module['moduleName']);

            return self::SUCCESS;
        }

        $review = ($this->reviewWorkflow ?? new ModuleReviewWorkflow($this->client))->run(
            $input,
            $output,
            $this->questionHelper(),
            $module,
            $moduleInfo,
        );
        if (!$review->continuePublishing) {
            return self::SUCCESS;
        }

        $metadata = $this->generateMetadataIfRequested($input, $output, $module, $moduleInfo, $review);
        $handoff = ($this->projectCreationHandoff ?? new DrupalOrgProjectCreationHandoff($this->client))->run(
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
    private function writeDryRun(array $module, array $moduleInfo, ?\mglaman\DrupalOrg\Entity\Project $project): void
    {
        $this->stdOut->writeln('Dry run: no changes will be made.');
        $this->stdOut->writeln('');
        $this->writeModuleContext($module, $moduleInfo);
        $this->stdOut->writeln(sprintf(
            'Drupal.org project exists: %s',
            $project === null ? 'no' : 'yes',
        ));
        $this->stdOut->writeln('');
        $this->stdOut->writeln('Planned actions:');

        $plannedActions = $project === null
            ? [
                'Offer an optional AI-assisted contribution review and local PDF report.',
                'Optionally generate Drupal.org project metadata from the AI module characterization.',
                'Open the Drupal.org project creation page for manual project creation.',
                'Verify that the Drupal.org project exists.',
                'Wait for the Drupal.org Git repository to become available.',
                'Prepare an isolated temporary workspace.',
                'Copy the module into the temporary workspace.',
                'Initialize the publication Git repository.',
                'Create the initial commit.',
                'Configure the Drupal.org Git remote.',
                'Push the initial branch.',
            ]
            : [
                sprintf('Use the existing Drupal.org project "%s".', $module['moduleName']),
                'Prepare an isolated temporary workspace.',
                'Copy the module into the temporary workspace.',
                'Initialize the publication Git repository.',
                'Create the initial commit.',
                'Configure the Drupal.org Git remote.',
                'Push the initial branch.',
            ];

        foreach ($plannedActions as $index => $plannedAction) {
            $this->stdOut->writeln(sprintf('  %d. %s', $index + 1, $plannedAction));
        }

        $this->stdOut->writeln('');
        $this->stdOut->writeln('Publication execution is not implemented yet.');
        $this->stdOut->writeln('Dry run complete. No changes were made.');
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
    ): ?ProjectMetadataResult {
        $analysis = $review->analysisResult;
        if ($review->runtime === null || !is_array($analysis) || !is_array($analysis['review'] ?? null)) {
            return null;
        }

        if (!$this->askConfirmation(
            $input,
            $output,
            'Generate Drupal.org project metadata automatically? [Y/n] ',
            true,
        )) {
            return null;
        }

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
            );
            (new ProjectMetadataRenderer())->render($output, $metadata);

            return $metadata;
        } catch (\Throwable) {
            $output->writeln('Could not generate Drupal.org project metadata automatically.');
            $output->writeln('You can enter the project metadata manually on Drupal.org.');

            return null;
        }
    }

    /**
     * @param array{resolvedPath: string, moduleName: string, infoFile: string, projectRoot: ?string, webRoot: ?string} $module
     * @param array{name: string, type: string, coreVersionRequirement: string, dependencies: list<string>} $moduleInfo
     */
    private function writeModuleContext(array $module, array $moduleInfo): void
    {
        $this->stdOut->writeln(sprintf('Module path: %s', $module['resolvedPath']));
        $this->stdOut->writeln(sprintf('Machine name: %s', $module['moduleName']));
        $this->stdOut->writeln(sprintf('Module info file: %s', $module['infoFile']));
        $this->stdOut->writeln(sprintf('Module name: %s', $moduleInfo['name']));
        $this->stdOut->writeln(sprintf('Module type: %s', $moduleInfo['type']));
        $this->stdOut->writeln(sprintf('Core version requirement: %s', $moduleInfo['coreVersionRequirement']));
    }

    private function writeNonInteractiveProjectCreationInstructions(string $machineName): void
    {
        $this->stdOut->writeln('The Drupal.org project does not exist and must be created manually before non-interactive publishing can continue.');
        $this->stdOut->writeln('');
        $this->stdOut->writeln('Create:');
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
