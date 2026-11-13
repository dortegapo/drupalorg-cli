<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Browser;

use mglaman\DrupalOrg\Browser\BrowserCompanionDistribution;
use mglaman\DrupalOrg\Browser\BrowserCompanionManager;
use mglaman\DrupalOrg\Browser\BrowserCompanionSetupStatus;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use mglaman\DrupalOrgCli\Browser\BrowserCompanionSetupInteractor;
use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Setup extends Command
{
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
            try {
                $manager->reset();
            } catch (\RuntimeException $exception) {
                $output->writeln('<error>' . $exception->getMessage() . '</error>');

                return BaseCommand::FAILURE;
            }
            $output->writeln('<info>Browser companion configuration reset.</info>');

            return BaseCommand::SUCCESS;
        }

        $questionHelper = $this->getHelper('question');
        if (!$questionHelper instanceof QuestionHelper) {
            throw new \LogicException('The question helper is not available.');
        }

        $status = (new BrowserCompanionSetupInteractor(
            $manager,
            new BrowserCompanionDistribution(),
            new BrowserPlatformDetector(),
        ))->run(
            $input,
            $output,
            $questionHelper,
            new BrowserLauncher(),
        );

        return match ($status) {
            BrowserCompanionSetupStatus::CONFIGURED => $this->configured($output),
            BrowserCompanionSetupStatus::ALREADY_CONFIGURED => $this->alreadyConfigured($output),
            BrowserCompanionSetupStatus::MANUAL_SELECTED,
            BrowserCompanionSetupStatus::DISTRIBUTION_UNAVAILABLE => $this->manual($output),
            BrowserCompanionSetupStatus::LAUNCH_FAILED => $this->failure(
                $output,
                'Could not open the browser companion installation page.',
            ),
            BrowserCompanionSetupStatus::SAVE_FAILED => $this->failure(
                $output,
                'Could not save the browser companion configuration.',
            ),
        };
    }

    private function configured(OutputInterface $output): int
    {
        $output->writeln('<info>✓ Browser companion configured.</info>');

        return BaseCommand::SUCCESS;
    }

    private function alreadyConfigured(OutputInterface $output): int
    {
        $output->writeln('<info>Browser companion is already configured.</info>');

        return BaseCommand::SUCCESS;
    }

    private function manual(OutputInterface $output): int
    {
        $output->writeln('<comment>Continuing with manual Drupal.org project creation.</comment>');

        return BaseCommand::SUCCESS;
    }

    private function failure(OutputInterface $output, string $message): int
    {
        $output->writeln('<error>' . $message . '</error>');
        $output->writeln('<comment>Continuing with manual Drupal.org project creation.</comment>');

        return BaseCommand::FAILURE;
    }
}
