<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Auth;

use mglaman\DrupalOrg\Auth\AuthenticationRequiredException;
use mglaman\DrupalOrg\Auth\Authenticator;
use mglaman\DrupalOrgCli\BrowserTrait;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Login extends Command
{
    use BrowserTrait;

    public function __construct(
        private readonly Authenticator $authenticator,
    ) {
        parent::__construct();
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

        // workaround until we have a way to configure the oauth client in drupalorg
        if (!(bool) $input->getOption('local')) {
            $this->stdOut->writeln(
                'Drupal.org OAuth is not configured yet. Use --local to use the development Keycloak environment.'
            );
            return self::FAILURE;
        }

        try {
            $result = $this->authenticator->authenticate(
                interactive: $input->isInteractive(),
                force: $force,
                onDeviceAuthorization: function (array $deviceAuthorization) use ($noBrowser): void {
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
                },
            );
        } catch (AuthenticationRequiredException $exception) {
            $this->stdErr->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return self::FAILURE;
        }

        if ($result->method === 'stored') {
            $this->stdOut->writeln('Already authenticated with stored credentials.');
        } elseif ($result->method === 'refreshed') {
            $this->stdOut->writeln('Authentication refreshed.');
        } else {
            $this->stdOut->writeln('Authentication successful.');
        }

        return self::SUCCESS;
    }
}
