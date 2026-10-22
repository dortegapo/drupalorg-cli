<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Action\Project\SearchProjectsAction;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Search extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('project:search')
            ->setAliases(['ps'])
            ->addArgument('query', InputArgument::REQUIRED, 'Business capability or keyword to search for')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of projects', '10')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Output format: text, json, md, llm.', 'text')
            ->setDescription('Searches Drupal.org projects by capability or keyword.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $query = (string) $input->getArgument('query');
        $result = (new SearchProjectsAction($this->client))($query, (int) $input->getOption('limit'));

        if ($this->writeFormatted($result, (string) $input->getOption('format'))) {
            return self::SUCCESS;
        }

        $output->writeln(sprintf('<info>Drupal.org projects</info> — search: <comment>%s</comment>', $query));
        foreach ($result->projects as $project) {
            $output->writeln(sprintf('- %s (%s)', $project['title'], $project['url']));
            if ($project['description'] !== '') {
                $output->writeln('  ' . $project['description']);
            }
        }

        return self::SUCCESS;
    }
}
