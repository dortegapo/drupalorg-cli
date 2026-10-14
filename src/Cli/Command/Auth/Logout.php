<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Auth;

use mglaman\DrupalOrg\Auth\TokenStorage;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
            ->setDescription('Remove locally stored Drupal.org credentials.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->tokenStorage->delete();
        $this->stdOut->writeln('Local authentication credentials removed.');

        return self::SUCCESS;
    }
}
