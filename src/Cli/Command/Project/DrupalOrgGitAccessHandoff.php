<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Action\Project\InspectDrupalOrgGitAccessAction;
use mglaman\DrupalOrg\Result\Project\DrupalOrgGitAccessResult;
use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Interactive SSH-key handoff used before a Drupal.org Git mutation. */
final class DrupalOrgGitAccessHandoff
{
    public function __construct(
        private readonly ?InspectDrupalOrgGitAccessAction $inspector = null,
        private readonly ?BrowserLauncher $browserLauncher = null,
        private readonly ?\Closure $sleeper = null,
    ) {
    }

    /**
     * @param callable(InputInterface, OutputInterface, string, bool): bool $confirm
     */
    public function run(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errorOutput,
        ProjectPublishProgressRenderer $progress,
        string $machineName,
        callable $confirm,
    ): ?DrupalOrgGitAccessResult {
        $progress->register([
            ProjectPublishProgressRenderer::CHECKING_GIT_ACCESS => 'Checking Drupal.org Git access',
        ]);
        $progress->start(ProjectPublishProgressRenderer::CHECKING_GIT_ACCESS);
        $inspector = $this->inspector ?? new InspectDrupalOrgGitAccessAction();
        $result = $inspector();
        if ($result->authenticated()) {
            $progress->complete(ProjectPublishProgressRenderer::CHECKING_GIT_ACCESS);

            return $result;
        }
        if ($result->status !== DrupalOrgGitAccessResult::AUTHENTICATION_MISSING || !$input->isInteractive()) {
            $progress->fail(ProjectPublishProgressRenderer::CHECKING_GIT_ACCESS);
            $this->writeFailure($errorOutput, $result, $machineName);

            return null;
        }

        $progress->suspend();
        $output->writeln('');
        $output->writeln('<fg=cyan;options=bold>DRUPAL.ORG GIT ACCESS</>');
        $output->writeln('');
        $output->writeln('Publishing source requires authenticated Git access.');
        $output->writeln('The command will try local SSH identities automatically; ssh-agent is not required.');
        $output->writeln('');
        $output->writeln('Test:');
        $output->writeln('  ssh -T git@git.drupal.org');
        $output->writeln('');
        $output->writeln('Add your SSH public key:');
        $output->writeln('  ' . InspectDrupalOrgGitAccessAction::SSH_KEY_SETTINGS_URL);
        $output->writeln('');
        if (!(bool) $input->getOption('no-browser')
            && $confirm($input, $output, 'Open Drupal.org SSH key settings? [Y/n] ', true)) {
            $opened = ($this->browserLauncher ?? new BrowserLauncher())->openDefault(
                InspectDrupalOrgGitAccessAction::SSH_KEY_SETTINGS_URL,
            );
            if (!$opened) {
                $output->writeln('Could not open a browser automatically.');
                $output->writeln(InspectDrupalOrgGitAccessAction::SSH_KEY_SETTINGS_URL);
            }
        }
        $output->writeln('');
        $output->writeln('Add your SSH public key in the browser.');
        $output->writeln('');
        $output->writeln('Waiting for Drupal.org Git authentication...');
        $output->writeln('Press Ctrl+C to stop.');

        foreach ([2, 3, 5, 5, 10] as $delay) {
            ($this->sleeper ?? static function (int $seconds): void {
                sleep($seconds);
            })($delay);
            $result = $inspector();
            if ($result->authenticated()) {
                $progress->complete(ProjectPublishProgressRenderer::CHECKING_GIT_ACCESS);
                $output->writeln('✓ Drupal.org Git authentication');

                return $result;
            }
            if ($result->status !== DrupalOrgGitAccessResult::AUTHENTICATION_MISSING) {
                $progress->fail(ProjectPublishProgressRenderer::CHECKING_GIT_ACCESS);
                $this->writeFailure($errorOutput, $result, $machineName);

                return null;
            }
        }

        while (true) {
            ($this->sleeper ?? static function (int $seconds): void {
                sleep($seconds);
            })(10);
            $result = $inspector();
            if ($result->authenticated()) {
                $progress->complete(ProjectPublishProgressRenderer::CHECKING_GIT_ACCESS);
                $output->writeln('✓ Drupal.org Git authentication');

                return $result;
            }
            if ($result->status !== DrupalOrgGitAccessResult::AUTHENTICATION_MISSING) {
                $progress->fail(ProjectPublishProgressRenderer::CHECKING_GIT_ACCESS);
                $this->writeFailure($errorOutput, $result, $machineName);

                return null;
            }
        }
    }

    private function writeFailure(
        OutputInterface $output,
        DrupalOrgGitAccessResult $result,
        string $machineName,
    ): void {
        match ($result->status) {
            DrupalOrgGitAccessResult::AUTHENTICATION_MISSING => $output->writeln(
                "<error>Drupal.org Git authentication is not configured, or the uploaded key does not match a local private key.\n\nTest the connection with:\n  ssh -T git@git.drupal.org\n\nAdd the matching SSH public key to Drupal.org:\n  " . InspectDrupalOrgGitAccessAction::SSH_KEY_SETTINGS_URL . '</error>',
            ),
            DrupalOrgGitAccessResult::HOST_KEY_FAILURE => $output->writeln(
                '<error>Drupal.org Git host key verification failed. Verify your SSH known_hosts configuration; it was not changed.</error>',
            ),
            DrupalOrgGitAccessResult::NETWORK_FAILURE => $output->writeln(
                '<error>Could not reach Drupal.org Git over the network.</error>',
            ),
            DrupalOrgGitAccessResult::SSH_UNAVAILABLE => $output->writeln(
                '<error>The ssh executable is unavailable. Install OpenSSH and rerun project:publish.</error>',
            ),
            default => $output->writeln(
                '<error>Could not inspect Drupal.org Git access for ' . $machineName . '.</error>',
            ),
        };
    }
}
