<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Auth;

use mglaman\DrupalOrg\Auth\TokenStorage;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Logout extends Command
{

    public function __construct(
        private readonly TokenStorage $tokenStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('auth:logout')
            ->setDescription('Remove locally stored Drupal.org credentials.')
            ->addOption(
                'local',
                null,
                InputOption::VALUE_NONE,
                'Use the local Keycloak development environment.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!(bool) $input->getOption('local')) {
            $this->stdOut->writeln(
                'Drupal.org OAuth is not configured yet. Use --local to use the development Keycloak environment.'
            );
            return self::FAILURE;
        }

        $this->tokenStorage->delete();
        $this->stdOut->writeln('Local authentication credentials removed.');

        return self::SUCCESS;
    }
}
