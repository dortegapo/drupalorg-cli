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

/** Human-only handoff for Drupal.org's official release form. */
final class DrupalOrgReleaseCreationHandoff
{
    public function __construct(
        private readonly Client $client,
        private readonly ?BrowserLauncher $browserLauncher = null,
        private readonly ?GetProjectReleasesAction $releaseLookupAction = null,
        private readonly ?\Closure $sleeper = null,
        private readonly ?ProjectPublishProgressRenderer $progress = null,
    ) {
    }

    public function run(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errorOutput,
        QuestionHelper $helper,
        Project $project,
        ProjectReleaseVersion $version,
    ): bool {
        $this->progress?->suspend();
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
        $output->writeln('');
        $output->writeln('Complete the release form in your browser and press Save.');
        $output->writeln('');
        $output->writeln('Waiting for Drupal.org to create release "' . $version->value . '"...');
        $output->writeln('This command will continue automatically when the release is available.');
        $output->writeln('');
        $output->writeln('Press Ctrl+C to stop.');

        return $this->waitForRelease($output, $errorOutput, $project, $version);
    }

    private function waitForRelease(
        OutputInterface $output,
        OutputInterface $errorOutput,
        Project $project,
        ProjectReleaseVersion $version,
    ): bool {
        $delays = [2, 3, 5, 5, 10];
        $attempt = 0;
        while (true) {
            try {
                $releases = ($this->releaseLookupAction ?? new GetProjectReleasesAction($this->client))($project, true, true)->releases;
            } catch (\Exception $exception) {
                $errorOutput->writeln(sprintf(
                    '<error>Could not check Drupal.org release: %s</error>',
                    $exception->getMessage(),
                ));

                return false;
            }
            foreach ($releases as $release) {
                if ($release->fieldReleaseVersion === $version->value) {
                    $this->progress?->complete(ProjectPublishProgressRenderer::PREPARING_RELEASE);
                    $this->progress?->suspend();
                    $output->writeln('');
                    $output->writeln('<fg=green;options=bold>FOUND</> Drupal.org release: ' . $version->value);
                    return true;
                }
            }

            $delay = $delays[min($attempt, count($delays) - 1)];
            ++$attempt;
            ($this->sleeper ?? static function (int $seconds): void {
                sleep($seconds);
            })($delay);
        }
    }
}
