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
     *     integrations: list<string>,
     *     categories: list<string>,
     *     ecosystem: list<string>,
     *     supporting_organizations: list<array{name: string, how_they_helped: string}>,
     *     issues: array{
     *         components: list<string>,
     *         submission_guidelines: string,
     *         summary_template: string,
     *         enable_tracker: bool
     *     },
     *     resources: array{
     *         homepage: string,
     *         changelog: string,
     *         demo: string,
     *         documentation: list<array{title: string, url: string}>,
     *         screenshots: list<string>
     *     }
     * }
     */
    public function validate(array $result): array
    {
        $required = [
            'summary',
            'description',
            'features',
            'integrations',
            'categories',
            'ecosystem',
            'supporting_organizations',
            'issues',
            'resources',
        ];
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
            'categories' => $this->validateCategories($result['categories']),
            'ecosystem' => $this->validateList('ecosystem', $result['ecosystem']),
            'supporting_organizations' => $this->validateOrganizations($result['supporting_organizations']),
            'issues' => $this->validateIssues($result['issues']),
            'resources' => $this->validateResources($result['resources']),
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

    /**
     * @return list<string>
     */
    private function validateCategories(mixed $value): array
    {
        $categories = $this->validateList('categories', $value);
        if (count($categories) > 3) {
            throw new \RuntimeException('Drupal.org project metadata field "categories" contains too many items.');
        }

        foreach ($categories as $category) {
            if (!in_array($category, DrupalOrgProjectFormData::MODULE_CATEGORIES, true)) {
                throw new \RuntimeException(sprintf(
                    'Drupal.org project metadata category "%s" is not a supported Drupal.org category.',
                    $category,
                ));
            }
        }

        return $categories;
    }

    /**
     * @return list<array{name: string, how_they_helped: string}>
     */
    private function validateOrganizations(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 10) {
            throw new \RuntimeException(
                'Drupal.org project metadata field "supporting_organizations" must be a list of at most ten objects.',
            );
        }

        $validated = [];
        foreach ($value as $organization) {
            if (!is_array($organization)
                || array_diff(array_keys($organization), ['name', 'how_they_helped']) !== []
                || array_diff(['name', 'how_they_helped'], array_keys($organization)) !== []
                || !is_string($organization['name'])
                || !is_string($organization['how_they_helped'])
                || trim($organization['name']) === ''
                || trim($organization['how_they_helped']) === ''
            ) {
                throw new \RuntimeException(
                    'Drupal.org project metadata supporting organizations must contain a name and how_they_helped.',
                );
            }
            $this->assertPlainText('supporting_organizations', $organization['name']);
            $this->assertPlainText('supporting_organizations', $organization['how_they_helped']);
            $validated[] = [
                'name' => $organization['name'],
                'how_they_helped' => $organization['how_they_helped'],
            ];
        }

        return $validated;
    }

    /**
     * @return array{
     *     components: list<string>,
     *     submission_guidelines: string,
     *     summary_template: string,
     *     enable_tracker: bool
     * }
     */
    private function validateIssues(mixed $value): array
    {
        if (!is_array($value)
            || array_diff(array_keys($value), [
                'components',
                'submission_guidelines',
                'summary_template',
                'enable_tracker',
            ]) !== []
            || array_diff([
                'components',
                'submission_guidelines',
                'summary_template',
                'enable_tracker',
            ], array_keys($value)) !== []
            || !is_bool($value['enable_tracker'])
        ) {
            throw new \RuntimeException('Drupal.org project metadata field "issues" is invalid.');
        }

        foreach (['submission_guidelines', 'summary_template'] as $field) {
            if (!is_string($value[$field])) {
                throw new \RuntimeException(sprintf(
                    'Drupal.org project metadata issue field "%s" must be a string.',
                    $field,
                ));
            }
            if ($value[$field] !== '') {
                $this->assertPlainText($field, $value[$field]);
            }
        }

        return [
            'components' => $this->validateList('issue components', $value['components']),
            'submission_guidelines' => $value['submission_guidelines'],
            'summary_template' => $value['summary_template'],
            'enable_tracker' => $value['enable_tracker'],
        ];
    }

    /**
     * @return array{
     *     homepage: string,
     *     changelog: string,
     *     demo: string,
     *     documentation: list<array{title: string, url: string}>,
     *     screenshots: list<string>
     * }
     */
    private function validateResources(mixed $value): array
    {
        $fields = ['homepage', 'changelog', 'demo', 'documentation', 'screenshots'];
        if (!is_array($value)
            || array_diff(array_keys($value), $fields) !== []
            || array_diff($fields, array_keys($value)) !== []
        ) {
            throw new \RuntimeException('Drupal.org project metadata field "resources" is invalid.');
        }

        $resources = [];
        foreach (['homepage', 'changelog', 'demo'] as $field) {
            if (!is_string($value[$field])) {
                throw new \RuntimeException(sprintf(
                    'Drupal.org project metadata resource "%s" must be a string.',
                    $field,
                ));
            }
            $resources[$field] = $this->validateUrl($field, $value[$field]);
        }

        if (!is_array($value['documentation']) || !array_is_list($value['documentation'])) {
            throw new \RuntimeException('Drupal.org project metadata documentation must be a list.');
        }
        $documentation = [];
        foreach ($value['documentation'] as $link) {
            if (!is_array($link)
                || array_diff(array_keys($link), ['title', 'url']) !== []
                || array_diff(['title', 'url'], array_keys($link)) !== []
                || !is_string($link['title'])
                || trim($link['title']) === ''
                || !is_string($link['url'])
            ) {
                throw new \RuntimeException('Drupal.org project metadata documentation link is invalid.');
            }
            $documentation[] = [
                'title' => $link['title'],
                'url' => $this->validateUrl('documentation', $link['url']),
            ];
        }
        $resources['documentation'] = $documentation;

        $screenshots = $this->validateUrlList('screenshots', $value['screenshots']);
        $resources['screenshots'] = $screenshots;

        return $resources;
    }

    private function validateUrl(string $field, string $value): string
    {
        if ($value !== '' && (filter_var($value, FILTER_VALIDATE_URL) === false
            || !preg_match('/^https?:\/\//i', $value))) {
            throw new \RuntimeException(sprintf(
                'Drupal.org project metadata resource "%s" must be an HTTP URL or empty.',
                $field,
            ));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function validateUrlList(string $field, mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 10) {
            throw new \RuntimeException(sprintf(
                'Drupal.org project metadata field "%s" must be a list of at most ten URLs.',
                $field,
            ));
        }

        $validated = [];
        foreach ($value as $url) {
            if (!is_string($url)) {
                throw new \RuntimeException(sprintf(
                    'Drupal.org project metadata field "%s" must contain URLs.',
                    $field,
                ));
            }
            $validated[] = $this->validateUrl($field, $url);
        }

        return $validated;
    }
}
