<?php

namespace mglaman\DrupalOrgCli\Command\Project;

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

        if (!file_exists($modulePath)) {
            $this->stdErr->writeln(sprintf('<error>Module path does not exist: %s</error>', $modulePath));
            return self::FAILURE;
        }

        if (!is_dir($modulePath)) {
            $this->stdErr->writeln(sprintf('<error>Module path is not a directory: %s</error>', $modulePath));
            return self::FAILURE;
        }

        $resolvedPath = realpath($modulePath);
        if ($resolvedPath === false) {
            $this->stdErr->writeln(sprintf('<error>Could not resolve module path: %s</error>', $modulePath));
            return self::FAILURE;
        }

        $this->stdOut->writeln(sprintf('Module path: %s', $resolvedPath));
        return self::SUCCESS;
    }
}
