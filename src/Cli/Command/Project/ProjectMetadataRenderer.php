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

        if ($output->isVerbose()) {
            $this->writeVerboseDetails($output, $metadata);
        }
    }

    private function writeSection(OutputInterface $output, string $title): void
    {
        $output->writeln('<comment>' . $title . '</comment>');
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
