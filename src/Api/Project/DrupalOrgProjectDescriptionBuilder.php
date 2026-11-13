<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;

final class DrupalOrgProjectDescriptionBuilder
{
    public function build(ProjectMetadataResult $metadata): string
    {
        $sections = [
            '<p>' . $this->escape($metadata->description) . '</p>',
        ];

        if ($metadata->features !== []) {
            $sections[] = $this->listSection('Features', $metadata->features);
        }

        if ($metadata->dependencies !== []) {
            $requirements = array_map(
                fn(string $dependency): string => 'Requires: ' . $dependency,
                $metadata->dependencies,
            );
            $sections[] = $this->listSection('Additional Requirements', $requirements);
        }

        if ($metadata->integrations !== []) {
            $sections[] = $this->listSection('Integrations', $metadata->integrations);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param list<string> $items
     */
    private function listSection(string $title, array $items): string
    {
        $html = '<h3>' . $this->escape($title) . "</h3>\n<ul>";
        foreach ($items as $item) {
            $html .= "\n  <li>" . $this->escape($item) . '</li>';
        }

        return $html . "\n</ul>";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
