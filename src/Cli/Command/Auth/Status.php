<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Auth;

use mglaman\DrupalOrg\Auth\TokenStorage;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Status extends Command
{

    private readonly \Closure $now;

    public function __construct(
        private readonly TokenStorage $tokenStorage,
        ?\Closure $now = null,
    ) {
        parent::__construct();
        $this->now = $now ?? static fn (): int => time();
    }

    protected function configure(): void
    {
        $this
            ->setName('auth:status')
            ->setDescription('Show the local Drupal.org authentication status.')
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

        $credentials = $this->tokenStorage->read();
        if ($credentials === null) {
            $this->stdOut->writeln('Not authenticated.');
            return self::FAILURE;
        }

        if (!array_key_exists('expires_at', $credentials)) {
            $this->stdOut->writeln('Authenticated with stored credentials (expiration unknown).');
            return self::SUCCESS;
        }

        if ($credentials['expires_at'] <= ($this->now)()) {
            $this->stdOut->writeln('Stored credentials are expired.');
            return self::FAILURE;
        }

        $this->stdOut->writeln('Authenticated with stored credentials.');
        return self::SUCCESS;
    }
}
