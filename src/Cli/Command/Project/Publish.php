<?php

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Publish extends Command
{

    protected function configure(): void
    {
        $this
            ->setName('project:publish')
            ->setDescription('Publishes a project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->stdOut->writeln('Hello world');
        return 0;
    }
}
