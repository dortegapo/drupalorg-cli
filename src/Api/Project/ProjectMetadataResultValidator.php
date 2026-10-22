<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class ProjectMetadataResultValidator
{
    /**
     * @param array<string, mixed> $result
     *
     * @return array{
     *     summary: string,
     *     description: string,
     *     features: list<string>,
     *     integrations: list<string>
     * }
     */
    public function validate(array $result): array
    {
        $required = ['summary', 'description', 'features', 'integrations'];
        if (array_diff(array_keys($result), $required) !== []
            || array_diff($required, array_keys($result)) !== []
        ) {
            throw new \RuntimeException('Drupal.org project metadata contains unexpected or missing fields.');
        }

        foreach (['summary', 'description'] as $field) {
            if (!is_string($result[$field]) || trim($result[$field]) === '') {
                throw new \RuntimeException(sprintf(
                    'Drupal.org project metadata field "%s" must be a non-empty string.',
                    $field,
                ));
            }
            $this->assertPlainText($field, $result[$field]);
        }

        return [
            'summary' => $result['summary'],
            'description' => $result['description'],
            'features' => $this->validateList('features', $result['features']),
            'integrations' => $this->validateList('integrations', $result['integrations']),
        ];
    }

    private function assertPlainText(string $field, string $value): void
    {
        if (preg_match('/<[^>]+>|https?:\/\/|www\.|\[[^\]]+\]\([^)]*\)/i', $value) === 1) {
            throw new \RuntimeException(sprintf(
                'Drupal.org project metadata field "%s" must contain plain text without HTML, links, or URLs.',
                $field,
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function validateList(string $field, mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException(sprintf(
                'Drupal.org project metadata field "%s" must be a list of strings.',
                $field,
            ));
        }
        if (count($value) > 10) {
            throw new \RuntimeException(sprintf(
                'Drupal.org project metadata field "%s" contains too many items.',
                $field,
            ));
        }

        $validated = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new \RuntimeException(sprintf(
                    'Drupal.org project metadata field "%s" must contain non-empty strings.',
                    $field,
                ));
            }
            $this->assertPlainText($field, $item);
            $validated[] = $item;
        }

        return $validated;
    }
}
