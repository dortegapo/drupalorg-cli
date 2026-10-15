<?php

namespace mglaman\DrupalOrgCli\Command\Project;

use GuzzleHttp\Exception\RequestException;
use mglaman\DrupalOrg\Project\ComposerContextLocator;
use mglaman\DrupalOrg\Project\ModuleInfoReader;
use mglaman\DrupalOrg\Project\ModuleLocator;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Publish extends Command
{

    protected function configure(): void
    {
        $this
            ->setName('project:publish')
            ->addArgument('module', InputArgument::REQUIRED, 'The module path')
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
