<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Entity\Project;
use mglaman\DrupalOrg\Project\DrupalOrgProjectDescriptionBuilder;
use mglaman\DrupalOrg\Project\DrupalOrgProjectFormData;
use mglaman\DrupalOrg\Project\DrupalOrgProjectPrefillUrlBuilder;
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrgCli\BrowserTrait;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

final class DrupalOrgProjectCreationHandoff
{
    use BrowserTrait;

    public const PROJECT_CREATION_URL = DrupalOrgProjectPrefillUrlBuilder::PROJECT_CREATION_URL;

    /**
     * @param \Closure|null $openUrl
     */
    public function __construct(
        private readonly Client $client,
        private readonly ?\Closure $openUrl = null,
        private readonly ?DrupalOrgProjectDescriptionBuilder $descriptionBuilder = null,
        private readonly ?DrupalOrgProjectPrefillUrlBuilder $prefillUrlBuilder = null,
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

        $prefillUrl = $this->preparePrefillUrl($input, $output, $helper, $metadata);
        if ($prefillUrl !== null) {
            $output->writeln('');
            $output->writeln('Browser prefill requires the drupalorg-cli browser companion.');
            $output->writeln('If the form is not prefilled, use the generated metadata above.');
        }

        $this->openProjectCreationPage($input, $output, $errorOutput, $prefillUrl);
        $output->writeln('');
        if ($prefillUrl !== null) {
            $output->writeln('Review the prefilled form in your browser and press Save.');
            $output->writeln('After saving:');
        } else {
            $output->writeln('After creating the project:');
        }
        $action = $this->askProjectCreationAction(
            $input,
            $output,
            $helper,
            'Press Enter to verify · type "open" to reopen · "cancel" to stop: ',
        );

        while ($action !== 'cancel') {
            if ($action === 'open') {
                $this->openProjectCreationPage($input, $output, $errorOutput, $prefillUrl);
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
        ?string $url = null,
    ): void {
        if ((bool) $input->getOption('no-browser')) {
            $output->writeln('Open this page to create the project:');
            $output->writeln(self::PROJECT_CREATION_URL);
            return;
        }

        $output->writeln($url === null
            ? 'Opening Drupal.org project creation page...'
            : 'Opening Drupal.org project creation page with generated metadata...');
        if ($this->openUrl !== null) {
            ($this->openUrl)($url ?? self::PROJECT_CREATION_URL, $errorOutput, $output);
            return;
        }

        $this->openUrl($url ?? self::PROJECT_CREATION_URL, $errorOutput, $output);
    }

    private function preparePrefillUrl(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        ?ProjectMetadataResult $metadata,
    ): ?string {
        if ($metadata === null
            || !$input->hasOption('no-browser')
            || (bool) $input->getOption('no-browser')
            || !(bool) $helper->ask($input, $output, new ConfirmationQuestion(
                'Prefill the Drupal.org project form automatically? [Y/n] ',
                true,
            ))) {
            return null;
        }

        if (mb_strlen($metadata->summary, 'UTF-8') > 200) {
            $output->writeln('<comment>Generated summary exceeds 200 characters; it will not be truncated.</comment>');
        }

        $maintenanceStatus = $this->askStatus(
            $input,
            $output,
            $helper,
            'Maintenance status',
            DrupalOrgProjectFormData::MAINTENANCE_STATUSES,
        );
        $developmentStatus = $this->askStatus(
            $input,
            $output,
            $helper,
            'Development status',
            DrupalOrgProjectFormData::DEVELOPMENT_STATUSES,
        );
        $descriptionHtml = ($this->descriptionBuilder ?? new DrupalOrgProjectDescriptionBuilder())->build($metadata);
        $formData = new DrupalOrgProjectFormData(
            title: $metadata->title,
            machineName: $metadata->machineName,
            projectType: DrupalOrgProjectFormData::PROJECT_TYPE_FULL,
            summary: $metadata->summary,
            descriptionHtml: $descriptionHtml,
            maintenanceStatus: $maintenanceStatus,
            developmentStatus: $developmentStatus,
            categories: [],
        );

        try {
            return ($this->prefillUrlBuilder ?? new DrupalOrgProjectPrefillUrlBuilder())->build($formData);
        } catch (\LengthException) {
            $output->writeln('<comment>Browser prefill is unavailable because the generated metadata is too large.</comment>');

            return null;
        }
    }

    /**
     * @param list<string> $choices
     */
    private function askStatus(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        string $label,
        array $choices,
    ): string {
        $options = [];
        $choiceValues = [];
        foreach ($choices as $index => $choice) {
            $number = (string) ($index + 1);
            $options[] = sprintf('  %s. %s', $number, $choice);
            $choiceValues[$number] = $choice;
        }

        $question = new Question(
            $label . "\n\n"
            . implode("\n", $options)
            . "\n\nSelection [1]: ",
            '1',
        );
        $question->setValidator(static function (mixed $selected) use ($choiceValues): string {
            $selected = (string) $selected;
            if (!array_key_exists($selected, $choiceValues)) {
                throw new \InvalidArgumentException('Invalid selection. Choose one of the displayed options.');
            }

            return $choiceValues[$selected];
        });

        return (string) $helper->ask($input, $output, $question);
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
