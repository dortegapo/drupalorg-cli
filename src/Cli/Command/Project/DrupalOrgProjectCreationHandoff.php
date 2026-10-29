<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Action\Project\GetProjectAction;
use mglaman\DrupalOrg\Browser\BrowserCompanionManager;
use mglaman\DrupalOrg\Browser\BrowserCompanionSetupStatus;
use mglaman\DrupalOrg\Browser\BrowserFamily;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use mglaman\DrupalOrg\Entity\Project;
use mglaman\DrupalOrg\Project\DrupalOrgProjectDescriptionBuilder;
use mglaman\DrupalOrg\Project\DrupalOrgProjectFormData;
use mglaman\DrupalOrg\Project\DrupalOrgProjectPrefillUrlBuilder;
use mglaman\DrupalOrg\Result\Project\DocumentationLink;
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrg\Result\Project\SupportingOrganization;
use mglaman\DrupalOrgCli\Browser\BrowserCompanionSetupInteractor;
use mglaman\DrupalOrgCli\Browser\BrowserLauncher;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DrupalOrgProjectCreationHandoff
{
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
        private readonly ?BrowserCompanionManager $browserCompanionManager = null,
        private readonly ?BrowserLauncher $browserLauncher = null,
        private readonly ?BrowserPlatformDetector $browserPlatformDetector = null,
        private readonly ?BrowserCompanionSetupInteractor $browserCompanionSetupInteractor = null,
        private readonly ?GetProjectAction $projectLookupAction = null,
        private readonly ?\Closure $sleeper = null,
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

        $prefillUrl = $this->preparePrefillUrl($input, $output, $errorOutput, $helper, $metadata);
        $this->progress?->suspend();
        if ($prefillUrl !== null) {
            $output->writeln('');
            $output->writeln('Automatic form filling is enabled through the browser companion.');
            $output->writeln('Review the generated metadata if any field is not prefilled.');
        }

        $opened = $this->openProjectCreationPage($input, $output, $errorOutput, $prefillUrl);
        if ($prefillUrl !== null && !$opened) {
            $prefillUrl = null;
            $this->writeManualPrefillFallback($output);
        }
        $output->writeln('');
        $this->writeWaitingForProject($output, $machineName);
        return $this->waitForProject($output, $errorOutput, $machineName);
    }

    private function openProjectCreationPage(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errorOutput,
        ?string $url = null,
    ): bool {
        if ((bool) $input->getOption('no-browser')) {
            $output->writeln('Open this page to create the project:');
            $output->writeln(self::PROJECT_CREATION_URL);
            return true;
        }

        $projectCreationUrl = $url ?? self::PROJECT_CREATION_URL;
        $output->writeln($url === null
            ? 'Opening Drupal.org project creation page...'
            : 'Opening Drupal.org project creation page with generated metadata...');
        if ($this->openUrl !== null) {
            ($this->openUrl)($projectCreationUrl, $errorOutput, $output);
            return true;
        }

        $manager = $this->browserCompanionManager ?? new BrowserCompanionManager();
        $launcher = $this->browserLauncher ?? new BrowserLauncher();
        $browserFamily = $url !== null ? $manager->getBrowserFamily() : null;
        $opened = $browserFamily !== null
            ? $launcher->open($browserFamily, $projectCreationUrl)
            : $launcher->openDefault($projectCreationUrl);
        if ($opened) {
            $errorOutput->writeln('<info>Opened</info>: ' . self::PROJECT_CREATION_URL);

            return true;
        }

        $errorOutput->writeln('<error>Could not open a browser automatically.</error>');
        $output->writeln(self::PROJECT_CREATION_URL);

        return false;
    }

    private function preparePrefillUrl(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errorOutput,
        QuestionHelper $helper,
        ?ProjectMetadataResult $metadata,
    ): ?string {
        if ($metadata === null
            || !$input->hasOption('no-browser')
            || (bool) $input->getOption('no-browser')) {
            return null;
        }

        $browserCompanionManager = $this->browserCompanionManager ?? new BrowserCompanionManager();
        if (!$browserCompanionManager->isConfigured()) {
            $this->progress?->suspend();
            $distribution = $browserCompanionManager->distribution();
            $platformDetector = $this->browserPlatformDetector ?? new BrowserPlatformDetector();
            if ($browserCompanionManager->publishedFamilies($platformDetector->browserFamilies()) === []) {
                $this->writeManualPrefillFallback(
                    $output,
                    'Browser companion distribution is not available yet.',
                );

                return null;
            }
            $setupInteractor = $this->browserCompanionSetupInteractor ?? new BrowserCompanionSetupInteractor(
                $browserCompanionManager,
                $distribution,
                $platformDetector,
            );
            $launcher = $this->browserLauncher ?? new BrowserLauncher();
            $status = $setupInteractor->run(
                $input,
                $output,
                $helper,
                $launcher,
                function (BrowserFamily $family, string $url) use ($errorOutput, $output, $launcher): bool {
                    if ($this->openUrl !== null) {
                        ($this->openUrl)($url, $errorOutput, $output);
                        return true;
                    }

                    return $launcher->open($family, $url);
                },
            );
            if (!in_array($status, [
                BrowserCompanionSetupStatus::CONFIGURED,
                BrowserCompanionSetupStatus::ALREADY_CONFIGURED,
            ], true)) {
                $this->writeManualPrefillFallback($output);

                return null;
            }
        }

        $browserFamily = $browserCompanionManager->getBrowserFamily();
        if ($browserFamily === null || !$browserCompanionManager->supportsCompanion($browserFamily)) {
            $this->writeManualPrefillFallback($output);

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
            $issueComponents = $metadata->issues->components !== []
                ? $metadata->issues->components
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
                supportingOrganizations: array_map(
                    static fn(SupportingOrganization $organization): array => $organization->toArray(),
                    $metadata->supportingOrganizations,
                ),
                issueComponents: $issueComponents,
                enableIssueTracker: $metadata->issues->enableTracker,
                issueGuidelines: $metadata->issues->submissionGuidelines,
                issueSummaryTemplate: $metadata->issues->summaryTemplate,
                homepage: $metadata->resources->homepage,
                changelog: $metadata->resources->changelog,
                demo: $metadata->resources->demo,
                documentation: array_map(
                    static fn(DocumentationLink $link): array => $link->toArray(),
                    $metadata->resources->documentation,
                ),
                screenshots: $metadata->resources->screenshots,
            );
            $url = ($this->prefillUrlBuilder ?? new DrupalOrgProjectPrefillUrlBuilder())->build($formData);
            $this->progress?->complete(ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM);

            return $url;
        } catch (\LengthException) {
            $this->progress?->fail(ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM);
            $output->writeln('<comment>Browser prefill is unavailable because the generated metadata is too large.</comment>');
            $this->writeManualPrefillFallback($output);

            return null;
        } catch (\InvalidArgumentException | \JsonException | \RuntimeException $exception) {
            $this->progress?->fail(ProjectPublishProgressRenderer::PREPARING_PROJECT_FORM);
            $this->progress?->detail('Browser prefill preparation failed: ' . $exception->getMessage());
            $this->writeManualPrefillFallback($output);

            return null;
        }
    }

    private function writeManualPrefillFallback(
        OutputInterface $output,
        string $reason = 'Automatic form filling is unavailable.',
    ): void {
        $output->writeln('<comment>' . $reason . '</comment>');
        $output->writeln('<comment>Continuing with manual Drupal.org project creation.</comment>');
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
            try {
                $projectResult = ($this->projectLookupAction ?? new GetProjectAction($this->client))($machineName, true);
                $project = $projectResult->project;
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

            $delay = $delays[min($attempt, count($delays) - 1)];
            ++$attempt;
            ($this->sleeper ?? static function (int $seconds): void {
                sleep($seconds);
            })($delay);
        }
    }

    private function writeFoundProject(OutputInterface $output, Project $project): void
    {
        $output->writeln('');
        $output->writeln($this->status('FOUND', 'green') . ' Drupal.org project: ' . $project->machineName);
        $output->writeln('  https://www.drupal.org/project/' . $project->machineName);
        $output->writeln('Resuming project publication workflow.');
    }

    private function status(string $value, string $color): string
    {
        return sprintf('<fg=%s;options=bold>%s</>', $color, $value);
    }
}
