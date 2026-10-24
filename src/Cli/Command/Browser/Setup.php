<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Browser;

use mglaman\DrupalOrg\Browser\BrowserCompanionManager;
use mglaman\DrupalOrgCli\BrowserTrait;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Setup extends Command
{
    use BrowserTrait;

    public function __construct(private readonly ?BrowserCompanionManager $manager = null)
    {
        parent::__construct('browser:setup');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Configure the optional Drupal.org browser companion.')
            ->addOption(
                'reset',
                null,
                InputOption::VALUE_NONE,
                'Remove the saved browser companion configuration.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = $this->manager ?? new BrowserCompanionManager();
        if ((bool) $input->getOption('reset')) {
            $manager->reset();
            $output->writeln('<info>Browser companion configuration reset.</info>');

            return BaseCommand::SUCCESS;
        }

        $questionHelper = $this->getHelper('question');
        if (!$questionHelper instanceof QuestionHelper) {
            throw new \LogicException('The question helper is not available.');
        }

        $manager->setup(
            $input,
            $output,
            $questionHelper,
            function (string $url) use ($output): bool {
                return $this->openUrl($url, $output, $output);
            },
        );

        return BaseCommand::SUCCESS;
    }
}
