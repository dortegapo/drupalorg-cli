<?php

namespace mglaman\DrupalOrgCli\Command\Project;

use GuzzleHttp\Exception\RequestException;
use mglaman\DrupalOrg\Project\ComposerContextLocator;
use mglaman\DrupalOrg\Project\ModuleLocator;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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
            $metadata = Yaml::parseFile($infoFile);
        } catch (ParseException $exception) {
            $this->stdErr->writeln(sprintf(
                '<error>Could not parse module info file: %s</error>',
                $exception->getMessage()
            ));
            return self::FAILURE;
        }

        if (!is_array($metadata)) {
            $this->stdErr->writeln('<error>Module info file must contain a YAML mapping.</error>');
            return self::FAILURE;
        }

        $infoFileName = $module['moduleName'] . '.info.yml';
        if (!array_key_exists('name', $metadata)) {
            $this->stdErr->writeln(sprintf('<error>Missing required "name" in %s.</error>', $infoFileName));
            return self::FAILURE;
        }

        if (!is_string($metadata['name']) || trim($metadata['name']) === '') {
            $this->stdErr->writeln(sprintf(
                '<error>Required "name" in %s must be a non-empty string.</error>',
                $infoFileName
            ));
            return self::FAILURE;
        }

        if (!array_key_exists('type', $metadata)) {
            $this->stdErr->writeln(sprintf('<error>Missing required "type" in %s.</error>', $infoFileName));
            return self::FAILURE;
        }

        $type = $metadata['type'];
        if (!is_string($type) || $type !== 'module') {
            $actualType = is_scalar($type) ? (string) $type : get_debug_type($type);
            $this->stdErr->writeln(sprintf(
                '<error>Expected Drupal project type "module", got "%s".</error>',
                $actualType
            ));
            return self::FAILURE;
        }

        if (!array_key_exists('core_version_requirement', $metadata)) {
            $this->stdErr->writeln(sprintf(
                '<error>Missing required "core_version_requirement" in %s.</error>',
                $infoFileName
            ));
            return self::FAILURE;
        }

        if (!is_string($metadata['core_version_requirement']) || trim($metadata['core_version_requirement']) === '') {
            $this->stdErr->writeln(sprintf(
                '<error>Required "core_version_requirement" in %s must be a non-empty string.</error>',
                $infoFileName
            ));
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
        $this->stdOut->writeln(sprintf('Module name: %s', $metadata['name']));
        $this->stdOut->writeln(sprintf('Module type: %s', $type));
        $this->stdOut->writeln(sprintf(
            'Core version requirement: %s',
            $metadata['core_version_requirement']
        ));
        $this->stdOut->writeln(sprintf(
            'Drupal.org project %s: %s',
            $project === null ? 'does not exist' : 'exists',
            $module['moduleName']
        ));

        return self::SUCCESS;
    }
}
