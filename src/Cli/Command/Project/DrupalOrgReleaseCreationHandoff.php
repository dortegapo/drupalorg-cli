<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Action\Project\GetProjectReleasesAction;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Entity\Project;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/** Human-only handoff for Drupal.org's official release form. */
final class DrupalOrgReleaseCreationHandoff
{
    public function __construct(private readonly Client $client, private readonly ?BrowserLauncher $browserLauncher = null)
    {
    }

    public function run(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errorOutput,
        QuestionHelper $helper,
        Project $project,
        ProjectReleaseVersion $version,
    ): bool {
        $url = 'https://www.drupal.org/node/add/project-release/' . $project->nid;
        $output->writeln('');
        $output->writeln('<fg=cyan;options=bold>CREATE DRUPAL.ORG RELEASE</>');
        $output->writeln('Select tag ' . $version->value . ', complete the release notes, review, and press Save.');
        if ((bool) $input->getOption('no-browser')) {
            $output->writeln('Open this page to create the release:');
            $output->writeln($url);
        } elseif (!($this->browserLauncher ?? new BrowserLauncher())->openDefault($url)) {
            $errorOutput->writeln('<error>Could not open a browser automatically.</error>');
            $output->writeln($url);
        }

        while (true) {
            $answer = (string) $helper->ask($input, $output, new Question('Press Enter to verify · type "open" to reopen · "cancel" to stop: ', ''));
            if ($answer === 'cancel') {
                return false;
            }
            if ($answer === 'open') {
                ($this->browserLauncher ?? new BrowserLauncher())->openDefault($url);
                continue;
            }
            $releases = (new GetProjectReleasesAction($this->client))($project)->releases;
            foreach ($releases as $release) {
                if ($release->fieldReleaseVersion === $version->value) {
                    return true;
                }
            }
            $output->writeln('Release has not propagated yet. Save it on Drupal.org and verify again, or cancel to resume later.');
        }
    }
}
