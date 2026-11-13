<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Action\Project\GetProjectAction;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Entity\Project;
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Hands the human-only Drupal.org project creation form to the browser. */
final class DrupalOrgProjectCreationHandoff
{
    public const PROJECT_CREATION_URL = 'https://www.drupal.org/node/add/project-module';

    /**
     * @param \Closure|null $openUrl Test seam for browser opening.
     */
    public function __construct(
        private readonly Client $client,
        private readonly ?\Closure $openUrl = null,
        private readonly ?ProjectPublishProgressRenderer $progress = null,
        private readonly ?BrowserLauncher $browserLauncher = null,
        private readonly ?GetProjectAction $projectLookupAction = null,
        private readonly ?\Closure $sleeper = null,
    ) {
    }

    public function run(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errorOutput,
        string $machineName,
        string $title,
        string $type,
        string $coreVersionRequirement,
        ?ProjectMetadataResult $metadata = null,
    ): ProjectCreationHandoffResult {
        $this->progress?->suspend();
        $output->writeln('');
        $output->writeln('<fg=cyan;options=bold>CREATE PROJECT ON DRUPAL.ORG</>');
        $output->writeln('');
        $output->writeln('Drupal.org currently requires contributed projects to be created');
        $output->writeln('through its web form.');
        $output->writeln('');
        $output->writeln(sprintf(
            '<fg=cyan;options=bold>Title</>         <fg=green;options=bold>%s</>',
            OutputFormatter::escape($title),
        ));
        $output->writeln(sprintf(
            '<fg=cyan;options=bold>Machine name</>  <fg=green;options=bold>%s</>',
            OutputFormatter::escape($machineName),
        ));
        if ($output->isVerbose()) {
            $output->writeln('Type          ' . ucfirst($type));
            $output->writeln('Core          ' . $coreVersionRequirement);
        }
        if ($metadata !== null) {
            (new ProjectMetadataRenderer())->render($output, $metadata);
            $output->writeln('');
            $output->writeln('Use the generated metadata above when completing the form.');
        }

        $this->openProjectCreationPage($input, $output, $errorOutput);
        $output->writeln('');
        $this->writeWaitingForProject($output, $machineName);

        return $this->waitForProject($output, $errorOutput, $machineName);
    }

    private function openProjectCreationPage(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errorOutput,
    ): void {
        if ((bool) $input->getOption('no-browser')) {
            $output->writeln('Open this page to create the project:');
            $output->writeln(self::PROJECT_CREATION_URL);

            return;
        }

        $output->writeln('Opening Drupal.org project creation page...');
        if ($this->openUrl !== null) {
            ($this->openUrl)(self::PROJECT_CREATION_URL, $errorOutput, $output);

            return;
        }
        if (($this->browserLauncher ?? new BrowserLauncher())->openDefault(self::PROJECT_CREATION_URL)) {
            $errorOutput->writeln('<info>Opened</info>: ' . self::PROJECT_CREATION_URL);

            return;
        }

        $errorOutput->writeln('<error>Could not open a browser automatically.</error>');
        $output->writeln(self::PROJECT_CREATION_URL);
    }

    private function writeWaitingForProject(OutputInterface $output, string $machineName): void
    {
        $output->writeln('Complete the project form in your browser and press Save.');
        $output->writeln('');
        $output->writeln('Waiting for Drupal.org to create "' . $machineName . '"...');
        $output->writeln('This command will continue automatically when the project is available.');
        $output->writeln('');
        $output->writeln('Press Ctrl+C to stop.');
    }

    private function waitForProject(
        OutputInterface $output,
        OutputInterface $errorOutput,
        string $machineName,
    ): ProjectCreationHandoffResult {
        $this->progress?->register([
            ProjectPublishProgressRenderer::VERIFYING_PROJECT => 'Verifying Drupal.org project',
        ]);
        $this->progress?->start(ProjectPublishProgressRenderer::VERIFYING_PROJECT);
        $delays = [2, 3, 5, 5, 10];
        $attempt = 0;
        while (true) {
            ++$attempt;
            $this->writePollingStatus($output, $machineName, $attempt);
            try {
                $project = ($this->projectLookupAction ?? new GetProjectAction($this->client))($machineName, true)->project;
            } catch (\Exception $exception) {
                $this->progress?->fail(ProjectPublishProgressRenderer::VERIFYING_PROJECT);
                $errorOutput->writeln(sprintf(
                    '<error>Could not check Drupal.org project: %s</error>',
                    $exception->getMessage(),
                ));

                return new ProjectCreationHandoffResult(ProjectCreationHandoffResult::FAILED);
            }
            if ($project !== null) {
                $this->progress?->complete(ProjectPublishProgressRenderer::VERIFYING_PROJECT);
                $this->progress?->suspend();
                $output->writeln('');
                $output->writeln('<fg=green;options=bold>FOUND</> Drupal.org project: ' . $project->machineName);
                $output->writeln('  https://www.drupal.org/project/' . $project->machineName);
                $output->writeln('Resuming project publication workflow.');

                return new ProjectCreationHandoffResult(ProjectCreationHandoffResult::FOUND, $project);
            }

            $delay = $delays[min($attempt - 1, count($delays) - 1)];
            ($this->sleeper ?? static function (int $seconds): void {
                sleep($seconds);
            })($delay);
        }
    }

    private function writePollingStatus(
        OutputInterface $output,
        string $machineName,
        int $attempt,
    ): void {
        // Keep decorated terminals on one live line while still emitting
        // periodic lines for buffered/non-decorated output and log files.
        if (!$output->isDecorated() && $attempt !== 1 && $attempt % 2 === 0) {
            return;
        }

        $message = sprintf(
            'Checking Drupal.org project "%s"... (attempt %d)',
            $machineName,
            $attempt,
        );
        if ($output->isDecorated()) {
            $output->write("\r" . $message);
        } else {
            $output->writeln($message);
        }
    }
}
