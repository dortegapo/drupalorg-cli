<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Entity\Project;
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrgCli\BrowserTrait;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class DrupalOrgProjectCreationHandoff
{
    use BrowserTrait;

    public const PROJECT_CREATION_URL = 'https://www.drupal.org/node/add/project-module';

    /**
     * @param \Closure|null $openUrl
     */
    public function __construct(
        private readonly Client $client,
        private readonly ?\Closure $openUrl = null,
    ) {
    }

    public function run(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errorOutput,
        QuestionHelper $helper,
        string $machineName,
        string $title,
        string $type,
        string $coreVersionRequirement,
        ?ProjectMetadataResult $metadata = null,
    ): ProjectCreationHandoffResult {
        $output->writeln('');
        $output->writeln('<fg=cyan;options=bold>CREATE PROJECT ON DRUPAL.ORG</>');
        $output->writeln('');
        $output->writeln('Drupal.org currently requires contributed projects to be created');
        $output->writeln('through its web form.');
        $output->writeln('');
        $output->writeln('Title         ' . $title);
        $output->writeln('Machine name  ' . $machineName);
        if ($output->isVerbose()) {
            $output->writeln('Type          ' . ucfirst($type));
            $output->writeln('Core          ' . $coreVersionRequirement);
        }
        if ($metadata !== null) {
            $output->writeln('');
            $output->writeln('Use the generated metadata above when completing the form.');
        }

        $this->openProjectCreationPage($input, $output, $errorOutput);
        $output->writeln('');
        $output->writeln('After creating the project:');
        $action = $this->askProjectCreationAction(
            $input,
            $output,
            $helper,
            'Press Enter to verify · type "open" to reopen · "cancel" to stop: ',
        );

        while ($action !== 'cancel') {
            if ($action === 'open') {
                $this->openProjectCreationPage($input, $output, $errorOutput);
            } else {
                try {
                    $project = $this->client->getProject($machineName);
                } catch (\Exception $exception) {
                    $errorOutput->writeln(sprintf(
                        '<error>Could not check Drupal.org project: %s</error>',
                        $exception->getMessage(),
                    ));

                    return new ProjectCreationHandoffResult(ProjectCreationHandoffResult::FAILED);
                }

                if ($project !== null) {
                    $this->writeFoundProject($output, $project);

                    return new ProjectCreationHandoffResult(ProjectCreationHandoffResult::FOUND, $project);
                }

                $output->writeln('Project not found yet.');
            }

            $action = $this->askProjectCreationAction(
                $input,
                $output,
                $helper,
                'Press Enter to check again · type "open" to reopen · "cancel" to stop: ',
            );
        }

        $output->writeln('Project publishing cancelled.');

        return new ProjectCreationHandoffResult(ProjectCreationHandoffResult::CANCELLED);
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

        $this->openUrl(self::PROJECT_CREATION_URL, $errorOutput, $output);
    }

    private function askProjectCreationAction(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        string $questionText,
    ): string {
        $question = new Question($questionText, '');
        $question->setValidator(static function (mixed $value): string {
            $value = strtolower(trim((string) $value));
            if ($value !== '' && !in_array($value, ['open', 'cancel'], true)) {
                throw new \InvalidArgumentException('Type "open", "cancel", or press Enter to continue.');
            }

            return $value;
        });

        return (string) $helper->ask($input, $output, $question);
    }

    private function writeFoundProject(OutputInterface $output, Project $project): void
    {
        $output->writeln('');
        $output->writeln($this->status('FOUND', 'green') . ' Drupal.org project: ' . $project->machineName);
        $output->writeln('  https://www.drupal.org/project/' . $project->machineName);
        $output->writeln('Repository setup is not implemented yet.');
    }

    private function status(string $value, string $color): string
    {
        return sprintf('<fg=%s;options=bold>%s</>', $color, $value);
    }
}
