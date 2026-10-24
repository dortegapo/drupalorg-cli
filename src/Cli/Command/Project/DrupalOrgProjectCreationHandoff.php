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
use Symfony\Component\Console\Formatter\OutputFormatter;
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
        private readonly ?ProjectPublishProgressRenderer $progress = null,
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

        $prefillUrl = $this->preparePrefillUrl($input, $output, $helper, $metadata);
        $this->progress?->suspend();
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
                    $this->progress?->register([
                        ProjectPublishProgressRenderer::VERIFYING_PROJECT => 'Verifying Drupal.org project',
                    ]);
                    $this->progress?->start(ProjectPublishProgressRenderer::VERIFYING_PROJECT);
                    $project = $this->client->getProject($machineName);
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
                    $this->writeFoundProject($output, $project);

                    return new ProjectCreationHandoffResult(ProjectCreationHandoffResult::FOUND, $project);
                }

                $this->progress?->complete(ProjectPublishProgressRenderer::VERIFYING_PROJECT);
                $this->progress?->suspend();
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
                '<fg=yellow;options=bold>Prefill the Drupal.org project form automatically?</> [Y/n] ',
                true,
            ))) {
            return null;
        }

        if (mb_strlen($metadata->summary, 'UTF-8') > 200) {
            $output->writeln('<comment>Generated summary exceeds 200 characters; it will not be truncated.</comment>');
        }

        $this->progress?->register([
            ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM => 'Preparing Drupal.org project form',
        ]);
        $this->progress?->start(ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM);

        try {
            $descriptionHtml = ($this->descriptionBuilder ?? new DrupalOrgProjectDescriptionBuilder())->build($metadata);
            $issues = $metadata->issues;
            $resources = $metadata->resources;
            $issueComponents = $issues['components'] !== []
                ? $issues['components']
                : DrupalOrgProjectFormData::DEFAULT_ISSUE_COMPONENTS;
            $formData = new DrupalOrgProjectFormData(
                title: $metadata->title,
                machineName: $metadata->machineName,
                projectType: DrupalOrgProjectFormData::PROJECT_TYPE_FULL,
                summary: $metadata->summary,
                descriptionHtml: $descriptionHtml,
                maintenanceStatus: DrupalOrgProjectFormData::MAINTENANCE_STATUSES[0],
                developmentStatus: DrupalOrgProjectFormData::DEVELOPMENT_STATUSES[0],
                categories: $metadata->categories,
                ecosystems: $metadata->ecosystems,
                supportingOrganizations: $metadata->supportingOrganizations,
                issueComponents: $issueComponents,
                enableIssueTracker: $issues['enableTracker'],
                issueGuidelines: $issues['submissionGuidelines'],
                issueSummaryTemplate: $issues['summaryTemplate'],
                homepage: $resources['homepage'],
                changelog: $resources['changelog'],
                demo: $resources['demo'],
                documentation: $resources['documentation'],
                screenshots: $resources['screenshots'],
            );
            $url = ($this->prefillUrlBuilder ?? new DrupalOrgProjectPrefillUrlBuilder())->build($formData);
            $this->progress?->complete(ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM);

            return $url;
        } catch (\LengthException) {
            $this->progress?->fail(ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM);
            $output->writeln('<comment>Browser prefill is unavailable because the generated metadata is too large.</comment>');

            return null;
        } catch (\Throwable $exception) {
            $this->progress?->fail(ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM);

            throw $exception;
        }
    }

    private function askProjectCreationAction(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        string $questionText,
    ): string {
        $question = new Question(
            '<fg=yellow;options=bold>' . $questionText . '</>',
            '',
        );
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
