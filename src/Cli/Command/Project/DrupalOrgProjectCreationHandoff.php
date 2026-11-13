<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Action\Project\GetProjectAction;
use mglaman\DrupalOrg\Browser\BrowserCompanionManager;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Entity\Project;
use mglaman\DrupalOrg\Project\DrupalOrgProjectDescriptionBuilder;
use mglaman\DrupalOrg\Project\DrupalOrgProjectFormData;
use mglaman\DrupalOrg\Project\DrupalOrgProjectPrefillUrlBuilder;
use mglaman\DrupalOrg\Result\Project\DocumentationLink;
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrg\Result\Project\SupportingOrganization;
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
        private readonly ?BrowserCompanionManager $browserCompanionManager = null,
        private readonly ?DrupalOrgProjectDescriptionBuilder $descriptionBuilder = null,
        private readonly ?DrupalOrgProjectPrefillUrlBuilder $prefillUrlBuilder = null,
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

        $prefillUrl = $this->preparePrefillUrl($input, $output, $metadata);
        if ($prefillUrl !== null) {
            $output->writeln('Automatic form filling is enabled through the browser companion.');
            $output->writeln('Review the generated metadata if any field is not prefilled.');
        }
        if (!$this->openProjectCreationPage($input, $output, $errorOutput, $prefillUrl)) {
            if ($prefillUrl !== null) {
                $output->writeln('<comment>Browser companion launch failed; continuing with manual form filling.</comment>');
                $this->openProjectCreationPage($input, $output, $errorOutput);
            }
        }
        $output->writeln('');
        $this->writeWaitingForProject($output, $machineName);

        return $this->waitForProject($output, $errorOutput, $machineName);
    }

    private function openProjectCreationPage(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errorOutput,
        ?string $prefillUrl = null,
    ): bool {
        if ($this->noBrowser($input)) {
            $output->writeln('Open this page to create the project:');
            $output->writeln(self::PROJECT_CREATION_URL);

            return true;
        }

        $url = $prefillUrl ?? self::PROJECT_CREATION_URL;
        $output->writeln($prefillUrl === null
            ? 'Opening Drupal.org project creation page...'
            : 'Opening Drupal.org project creation page with generated metadata...');
        if ($this->openUrl !== null) {
            try {
                ($this->openUrl)($url, $errorOutput, $output);
            } catch (\Throwable $exception) {
                $errorOutput->writeln('<error>Could not open a browser automatically: ' . $exception->getMessage() . '</error>');
                if ($prefillUrl === null) {
                    $output->writeln(self::PROJECT_CREATION_URL);
                }

                return false;
            }

            return true;
        }
        $launcher = $this->browserLauncher ?? new BrowserLauncher();
        $opened = $prefillUrl !== null
            ? $this->openWithCompanion($launcher, $url)
            : $launcher->openDefault($url);
        if ($opened) {
            $errorOutput->writeln('<info>Opened</info>: ' . self::PROJECT_CREATION_URL);

            return true;
        }

        $errorOutput->writeln('<error>Could not open a browser automatically.</error>');
        if ($prefillUrl === null) {
            $output->writeln(self::PROJECT_CREATION_URL);
        }

        return false;
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
        $delays = [1, 2, 3, 5];
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
            $this->waitBeforeNextCheck($output, $machineName, $delay);
        }
    }

    private function waitBeforeNextCheck(OutputInterface $output, string $machineName, int $delay): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($delay);

            return;
        }

        for ($remaining = $delay; $remaining > 0; --$remaining) {
            if ($output->isDecorated()) {
                $output->write(sprintf(
                    "\rChecking Drupal.org project \"%s\"... next check in %ds",
                    $machineName,
                    $remaining,
                ));
            }
            sleep(1);
        }
        if ($output->isDecorated()) {
            $output->write("\r\033[2K");
        }
    }

    private function preparePrefillUrl(
        InputInterface $input,
        OutputInterface $output,
        ?ProjectMetadataResult $metadata,
    ): ?string {
        if ($metadata === null || $this->noBrowser($input)) {
            return null;
        }

        $manager = $this->browserCompanionManager ?? new BrowserCompanionManager();
        if (!$manager->isConfigured()) {
            return null;
        }
        $browserFamily = $manager->getBrowserFamily();
        if ($browserFamily === null || !$manager->supportsCompanion($browserFamily)) {
            return null;
        }

        $this->progress?->register([
            ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM => 'Preparing Drupal.org project form',
        ]);
        $this->progress?->start(ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM);
        try {
            $issues = $metadata->issues;
            $resources = $metadata->resources;
            $supportingOrganizations = array_map(
                static fn(SupportingOrganization $organization): array => $organization->toArray(),
                $metadata->supportingOrganizations,
            );
            $documentation = array_map(
                static fn(DocumentationLink $link): array => $link->toArray(),
                $resources->documentation,
            );
            $formData = new DrupalOrgProjectFormData(
                title: $metadata->title,
                machineName: $metadata->machineName,
                projectType: DrupalOrgProjectFormData::PROJECT_TYPE_FULL,
                summary: $metadata->summary,
                descriptionHtml: ($this->descriptionBuilder ?? new DrupalOrgProjectDescriptionBuilder())->build($metadata),
                maintenanceStatus: DrupalOrgProjectFormData::MAINTENANCE_STATUSES[0],
                developmentStatus: DrupalOrgProjectFormData::DEVELOPMENT_STATUSES[0],
                categories: $metadata->categories,
                ecosystems: $metadata->ecosystems,
                supportingOrganizations: $supportingOrganizations,
                issueComponents: $issues->components !== []
                    ? $issues->components
                    : DrupalOrgProjectFormData::DEFAULT_ISSUE_COMPONENTS,
                enableIssueTracker: $issues->enableTracker,
                issueGuidelines: $issues->submissionGuidelines,
                issueSummaryTemplate: $issues->summaryTemplate,
                homepage: $resources->homepage,
                changelog: $resources->changelog,
                demo: $resources->demo,
                documentation: $documentation,
                screenshots: $resources->screenshots,
            );
            $url = ($this->prefillUrlBuilder ?? new DrupalOrgProjectPrefillUrlBuilder())->build($formData);
            $this->progress?->complete(ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM);

            return $url;
        } catch (\LengthException) {
            $this->progress?->fail(ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM);
            $output->writeln('<comment>Browser prefill is unavailable because the generated metadata is too large.</comment>');
        } catch (\InvalidArgumentException | \JsonException | \RuntimeException $exception) {
            $this->progress?->fail(ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM);
            $this->progress?->detail('Browser prefill preparation failed: ' . $exception->getMessage());
        }

        $output->writeln('<comment>Continuing with manual Drupal.org project creation.</comment>');

        return null;
    }

    private function openWithCompanion(BrowserLauncher $launcher, string $url): bool
    {
        $family = ($this->browserCompanionManager ?? new BrowserCompanionManager())->getBrowserFamily();

        return $family !== null && $launcher->open($family, $url);
    }

    private function noBrowser(InputInterface $input): bool
    {
        return $input->hasOption('no-browser') && (bool) $input->getOption('no-browser');
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
