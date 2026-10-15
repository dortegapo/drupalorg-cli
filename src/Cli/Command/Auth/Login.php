<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Auth;

use mglaman\DrupalOrg\Auth\DeviceAuthorizationClient;
use mglaman\DrupalOrg\Auth\TokenStorage;
use mglaman\DrupalOrgCli\BrowserTrait;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Login extends Command
{
    use BrowserTrait;

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
            ->setDescription('Authenticate with Drupal.org.')
            ->addOption(
                'local',
                null,
                InputOption::VALUE_NONE,
                'Use the local Keycloak development environment.'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Authenticate again even when usable local credentials already exist.'
            )
            ->addOption(
                'no-browser',
                null,
                InputOption::VALUE_NONE,
                'Do not open the verification URL automatically.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $noBrowser = (bool) $input->getOption('no-browser');

        if (!(bool) $input->getOption('local')) {
            $this->stdOut->writeln(
                'drupal.org oauth is not configured yet. Use --local to use the development Keycloak environment.'
            );
            return self::FAILURE;
        }

        if (!$force) {
            $storedCredentials = $this->tokenStorage->read();
            if ($storedCredentials !== null
                && (!array_key_exists('expires_at', $storedCredentials)
                    || $storedCredentials['expires_at'] > ($this->now)())) {
                $this->stdOut->writeln('Already authenticated with stored credentials.');
                return self::SUCCESS;
            }
        }

        $deviceAuthorization = $this->oauthClient->requestDeviceAuthorization();

        $verificationUri = $deviceAuthorization['verification_uri_complete']
            ?? $deviceAuthorization['verification_uri'];
        if ($noBrowser) {
            if (isset($deviceAuthorization['verification_uri_complete'])) {
                $this->stdOut->writeln('Open this URL to authenticate:');
                $this->stdOut->writeln($verificationUri);
            } else {
                $this->stdOut->writeln('Open:');
                $this->stdOut->writeln($verificationUri);
                $this->stdOut->writeln('Enter code:');
                $this->stdOut->writeln($deviceAuthorization['user_code']);
            }
        } else {
            $this->openUrl($verificationUri, $this->stdErr, $this->stdOut);
            if (!isset($deviceAuthorization['verification_uri_complete'])) {
                $this->stdOut->writeln('Enter code:');
                $this->stdOut->writeln($deviceAuthorization['user_code']);
            }
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
