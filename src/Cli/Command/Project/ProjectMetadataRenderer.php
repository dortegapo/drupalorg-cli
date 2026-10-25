<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use Symfony\Component\Console\Output\OutputInterface;

final class ProjectMetadataRenderer
{
    public function render(
        OutputInterface $output,
        ProjectMetadataResult $metadata,
    ): void {
        $output->writeln('');
        $output->writeln('<fg=cyan;options=bold>DRUPAL.ORG METADATA</>');
        $output->writeln('');
        $this->writeSection($output, 'Summary');
        $output->writeln($metadata->summary);
        $output->writeln('');
        $this->writeSection($output, 'Description');
        $output->writeln($metadata->description);
        $output->writeln('');
        $this->writeSection($output, 'Features');
        foreach ($metadata->features as $feature) {
            $output->writeln('- ' . $feature);
        }
        $output->writeln('');
        $this->writeSection($output, 'Integrations');
        if ($metadata->integrations === []) {
            $output->writeln('None declared/generated.');
        } else {
            foreach ($metadata->integrations as $integration) {
                $output->writeln('- ' . $integration);
            }
        }
        $output->writeln('');
        $this->writeSection($output, 'Module categories');
        $this->writeListOrEmpty($output, $metadata->categories);
        $this->writeSection($output, 'Maintenance status');
        $output->writeln('Actively maintained');
        $this->writeSection($output, 'Development status');
        $output->writeln('Under active development');
        $this->writeSection($output, 'Ecosystem');
        $this->writeListOrEmpty($output, $metadata->ecosystems);
        $this->writeSection($output, 'Supporting organizations');
        if ($metadata->supportingOrganizations === []) {
            $output->writeln('None declared/generated.');
        } else {
            foreach ($metadata->supportingOrganizations as $organization) {
                $output->writeln(sprintf(
                    '- %s: %s',
                    $organization->name,
                    $organization->howTheyHelped,
                ));
            }
        }
        $this->writeSection($output, 'Issues');
        $output->writeln('Components:');
        $this->writeListOrEmpty($output, $metadata->issues->components);
        $output->writeln('Issue tracker: ' . ($metadata->issues->enableTracker ? 'enabled' : 'disabled'));
        $output->writeln('Submission guidelines: ' . ($metadata->issues->submissionGuidelines !== ''
            ? $metadata->issues->submissionGuidelines
            : 'None declared/generated.'));
        $output->writeln('Summary template: ' . ($metadata->issues->summaryTemplate !== ''
            ? $metadata->issues->summaryTemplate
            : 'None declared/generated.'));
        $this->writeSection($output, 'Resources');
        $resourceUrls = array_filter([
            'Homepage' => $metadata->resources->homepage,
            'Changelog' => $metadata->resources->changelog,
            'Demo' => $metadata->resources->demo,
        ]);
        foreach ($resourceUrls as $label => $url) {
            $output->writeln(sprintf('- %s: %s', $label, $url));
        }
        foreach ($metadata->resources->documentation as $documentation) {
            $output->writeln(sprintf('- %s: %s', $documentation['title'], $documentation['url']));
        }
        foreach ($metadata->resources->screenshots as $screenshot) {
            $output->writeln('- Screenshot: ' . $screenshot);
        }
        if ($resourceUrls === [] && $metadata->resources->documentation === [] && $metadata->resources->screenshots === []) {
            $output->writeln('None declared/generated.');
        }

        if ($output->isVerbose()) {
            $this->writeVerboseDetails($output, $metadata);
        }
    }

    private function writeSection(OutputInterface $output, string $title): void
    {
        $output->writeln('<comment>' . $title . '</comment>');
    }

    /**
     * @param list<string> $items
     */
    private function writeListOrEmpty(OutputInterface $output, array $items): void
    {
        if ($items === []) {
            $output->writeln('None declared/generated.');
            return;
        }

        foreach ($items as $item) {
            $output->writeln('- ' . $item);
        }
    }

    private function writeVerboseDetails(OutputInterface $output, ProjectMetadataResult $metadata): void
    {
        $output->writeln('');
        $output->writeln('<comment>Metadata details</comment>');
        $output->writeln('Title: ' . $metadata->title);
        $output->writeln('Machine name: ' . $metadata->machineName);
        $output->writeln('Project type: ' . $metadata->projectType);
        $output->writeln('Core requirement: ' . $metadata->coreVersionRequirement);
        $output->writeln('Declared dependencies:');
        if ($metadata->dependencies === []) {
            $output->writeln('  None declared.');
        } else {
            foreach ($metadata->dependencies as $dependency) {
                $output->writeln('  - ' . $dependency);
            }
        }
        $output->writeln(sprintf('AI: %s / %s', $metadata->providerId, $metadata->modelId));
        $output->writeln(sprintf(
            'Tokens: input %s · output %s · total %s',
            $this->formatTokenValue($metadata->tokenUsage['input']),
            $this->formatTokenValue($metadata->tokenUsage['output']),
            $this->formatTokenValue($metadata->tokenUsage['total']),
        ));
    }

    private function formatTokenValue(?int $value): string
    {
        return $value === null ? 'unavailable' : (string) $value;
    }
}
