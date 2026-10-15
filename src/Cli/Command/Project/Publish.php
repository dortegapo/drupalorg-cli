<?php

namespace mglaman\DrupalOrgCli\Command\Project;

use GuzzleHttp\Exception\RequestException;
use mglaman\DrupalOrg\Project\ComposerContextLocator;
use mglaman\DrupalOrg\Project\ModuleInfoReader;
use mglaman\DrupalOrg\Project\ModuleLocator;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Publish extends Command
{

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
            ->setDescription('Publishes a project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modulePath = (string) $input->getArgument('module');

        try {
            $module = (new ModuleLocator(new ComposerContextLocator()))->locate($modulePath);
        } catch (\RuntimeException $exception) {
            $this->stdErr->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return self::FAILURE;
        }

        $resolvedPath = $module['resolvedPath'];
        $infoFile = $module['infoFile'];

        try {
            $moduleInfo = (new ModuleInfoReader())->read($infoFile, $module['moduleName']);
        } catch (\RuntimeException $exception) {
            $this->stdErr->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return self::FAILURE;
        }

        try {
            $project = $this->client->getProject($module['moduleName']);
        } catch (RequestException $exception) {
            if ($exception->getResponse()?->getStatusCode() !== 404) {
                $this->stdErr->writeln(sprintf(
                    '<error>Could not check Drupal.org project: %s</error>',
                    $exception->getMessage()
                ));
                return self::FAILURE;
            }
            $project = null;
        } catch (\Exception $exception) {
            $this->stdErr->writeln(sprintf(
                '<error>Could not check Drupal.org project: %s</error>',
                $exception->getMessage()
            ));
            return self::FAILURE;
        }

        if ((bool) $input->getOption('dry-run')) {
            $this->stdOut->writeln('Dry run: no changes will be made.');
            $this->stdOut->writeln('');
            $this->stdOut->writeln(sprintf('Module path: %s', $resolvedPath));
            $this->stdOut->writeln(sprintf('Machine name: %s', $module['moduleName']));
            $this->stdOut->writeln(sprintf('Module info file: %s', $infoFile));
            $this->stdOut->writeln(sprintf('Module name: %s', $moduleInfo['name']));
            $this->stdOut->writeln(sprintf('Module type: %s', $moduleInfo['type']));
            $this->stdOut->writeln(sprintf(
                'Core version requirement: %s',
                $moduleInfo['coreVersionRequirement']
            ));
            $this->stdOut->writeln(sprintf(
                'Drupal.org project exists: %s',
                $project === null ? 'no' : 'yes'
            ));
            $this->stdOut->writeln('');
            $this->stdOut->writeln('Planned actions:');

            $plannedActions = $project === null
                ? [
                    'Authenticate with Drupal.org automatically if required.',
                    sprintf('Create Drupal.org project "%s".', $module['moduleName']),
                    'Wait for repository provisioning.',
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

            return self::SUCCESS;
        }

        $this->stdOut->writeln(sprintf('Module path: %s', $resolvedPath));
        $this->stdOut->writeln(sprintf('Module info file: %s', $infoFile));
        $this->stdOut->writeln(sprintf('Module name: %s', $moduleInfo['name']));
        $this->stdOut->writeln(sprintf('Module type: %s', $moduleInfo['type']));
        $this->stdOut->writeln(sprintf(
            'Core version requirement: %s',
            $moduleInfo['coreVersionRequirement']
        ));
        $this->stdOut->writeln(sprintf(
            'Drupal.org project %s: %s',
            $project === null ? 'does not exist' : 'exists',
            $module['moduleName']
        ));

        return self::SUCCESS;
    }
}
