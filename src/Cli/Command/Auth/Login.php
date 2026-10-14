<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Auth;

use mglaman\DrupalOrg\Auth\DeviceAuthorizationClient;
use mglaman\DrupalOrg\Auth\TokenStorage;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Login extends Command
{

    private readonly \Closure $now;

    public function __construct(
        private readonly DeviceAuthorizationClient $oauthClient,
        private readonly TokenStorage $tokenStorage,
        private readonly ?\Closure $sleep = null,
        ?\Closure $now = null,
    ) {
        parent::__construct();
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    protected function configure(): void
    {
        $this
            ->setName('auth:login')
            ->setDescription('Authenticate with Drupal.org.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deviceAuthorization = $this->oauthClient->requestDeviceAuthorization();

        if (isset($deviceAuthorization['verification_uri_complete'])) {
            $this->stdOut->writeln('Open this URL to authenticate:');
            $this->stdOut->writeln($deviceAuthorization['verification_uri_complete']);
        } else {
            $this->stdOut->writeln('Open:');
            $this->stdOut->writeln($deviceAuthorization['verification_uri']);
            $this->stdOut->writeln('Enter code:');
            $this->stdOut->writeln($deviceAuthorization['user_code']);
        }
        $this->stdOut->writeln('Waiting for authorization...');

        $credentials = $this->oauthClient->waitForToken(
            $deviceAuthorization,
            $this->sleep,
            $this->now,
        );

        $storedCredentials = [
            'access_token' => $credentials['access_token'],
        ];
        if (array_key_exists('refresh_token', $credentials)) {
            $storedCredentials['refresh_token'] = $credentials['refresh_token'];
        }
        if (array_key_exists('expires_in', $credentials)) {
            $storedCredentials['expires_at'] = (int) ($this->now)() + $credentials['expires_in'];
        }
        $storedCredentials['token_type'] = $credentials['token_type'];
        if (array_key_exists('scope', $credentials)) {
            $storedCredentials['scope'] = $credentials['scope'];
        }

        $this->tokenStorage->write($storedCredentials);
        $this->stdOut->writeln('Authentication successful.');

        return self::SUCCESS;
    }
}
