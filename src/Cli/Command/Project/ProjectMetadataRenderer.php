<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use Symfony\Component\Console\Output\OutputInterface;

final class ProjectMetadataRenderer
{
    /**
     * @param array{moduleName: string} $module
     * @param array{name: string, coreVersionRequirement: string, dependencies: list<string>} $moduleInfo
     */
    public function render(
        OutputInterface $output,
        ProjectMetadataResult $metadata,
        array $module,
        array $moduleInfo,
    ): void {
        $output->writeln('');
        $output->writeln('Drupal.org project metadata');
        $output->writeln('');
        $output->writeln('Title');
        $output->writeln($moduleInfo['name']);
        $output->writeln('');
        $output->writeln('Machine name');
        $output->writeln($module['moduleName']);
        $output->writeln('');
        $output->writeln('Summary');
        $output->writeln($metadata->summary);
        $output->writeln('');
        $output->writeln('Description');
        $output->writeln($metadata->description);
        $output->writeln('');
        $output->writeln('Features');
        foreach ($metadata->features as $feature) {
            $output->writeln('- ' . $feature);
        }
        $output->writeln('');
        $output->writeln('Integrations');
        if ($metadata->integrations === []) {
            $output->writeln('None declared/generated.');
        } else {
            foreach ($metadata->integrations as $integration) {
                $output->writeln('- ' . $integration);
            }
        }
        $output->writeln('');
        $output->writeln('Core requirement');
        $output->writeln($moduleInfo['coreVersionRequirement']);
        $output->writeln('');
        $output->writeln('Declared dependencies');
        if ($moduleInfo['dependencies'] === []) {
            $output->writeln('None declared.');
        } else {
            foreach ($moduleInfo['dependencies'] as $dependency) {
                $output->writeln('- ' . $dependency);
            }
        }
        $output->writeln('');
        $output->writeln(sprintf('Metadata AI: %s / %s', $metadata->providerId, $metadata->modelId));
        $output->writeln(sprintf(
            'Tokens used: input %s, output %s, total %s',
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
