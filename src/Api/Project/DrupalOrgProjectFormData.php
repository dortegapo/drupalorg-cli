<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class DrupalOrgProjectFormData
{
    /** @var list<string> */
    public const MODULE_CATEGORIES = [
        'Access control',
        'Accessibility',
        'Administration tools',
        'Artificial Intelligence (AI)',
        'Automation',
        'Content display',
        'Content editing experience',
        'Decoupled',
        'Developer tools',
        'E-commerce',
        'Import and export',
        'Integrations',
        'Media',
        'Multilingual',
        'Performance',
        'Search engine optimization (SEO)',
        'Security',
        'Site search',
        'Site structure',
        'User engagement',
    ];

    /** @var list<string> */
    public const DEFAULT_ISSUE_COMPONENTS = [
        'Code',
        'Documentation',
        'Miscellaneous',
        'User interface',
    ];

    /** @var list<string> */
    public readonly array $categories;

    /** @var list<string> */
    public readonly array $ecosystems;

    /** @var list<array{name: string, howTheyHelped: string}> */
    public readonly array $supportingOrganizations;

    /** @var list<string> */
    public readonly array $issueComponents;

    /** @var list<array{title: string, url: string}> */
    public readonly array $documentation;

    /** @var list<string> */
    public readonly array $screenshots;

    public const VERSION = 2;

    public const PROJECT_TYPE_FULL = 'full';

    public const MAINTENANCE_STATUSES = [
        'Actively maintained',
        'Minimally maintained',
        'Seeking co-maintainer(s)',
        'Seeking new maintainer',
        'Unsupported',
    ];

    public const DEVELOPMENT_STATUSES = [
        'Under active development',
        'Maintenance fixes only',
        'No further development',
        'Obsolete',
    ];

    /**
     * @param array<int, mixed> $categories
     * @param array<int, mixed> $ecosystems
     * @param array<int, mixed> $supportingOrganizations
     * @param array<int, mixed> $issueComponents
     * @param array<int, mixed> $documentation
     * @param array<int, mixed> $screenshots
     */
    public function __construct(
        public readonly string $title,
        public readonly string $machineName,
        public readonly string $projectType,
        public readonly string $summary,
        public readonly string $descriptionHtml,
        public readonly string $maintenanceStatus,
        public readonly string $developmentStatus,
        array $categories = [],
        public readonly int $version = self::VERSION,
        array $ecosystems = [],
        array $supportingOrganizations = [],
        array $issueComponents = self::DEFAULT_ISSUE_COMPONENTS,
        public readonly bool $enableIssueTracker = true,
        public readonly string $issueGuidelines = '',
        public readonly string $issueSummaryTemplate = '',
        public readonly string $homepage = '',
        public readonly string $changelog = '',
        public readonly string $demo = '',
        array $documentation = [],
        array $screenshots = [],
    ) {
        if ($this->version !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported Drupal.org project form data version.');
        }

        foreach ([
            'title' => $this->title,
            'machine name' => $this->machineName,
            'summary' => $this->summary,
            'description HTML' => $this->descriptionHtml,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException(sprintf(
                    'Drupal.org project form data %s cannot be empty.',
                    $field,
                ));
            }
        }

        if ($this->projectType !== self::PROJECT_TYPE_FULL) {
            throw new \InvalidArgumentException('Drupal.org project form data must use project type "full".');
        }

        if (!in_array($this->maintenanceStatus, self::MAINTENANCE_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid Drupal.org project maintenance status.');
        }

        if (!in_array($this->developmentStatus, self::DEVELOPMENT_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid Drupal.org project development status.');
        }

        if (!array_is_list($categories) || count($categories) > 3) {
            throw new \InvalidArgumentException(
                'Drupal.org project form data must contain at most three categories.',
            );
        }

        $validatedCategories = [];
        foreach ($categories as $category) {
            if (!is_string($category) || !in_array($category, self::MODULE_CATEGORIES, true)) {
                throw new \InvalidArgumentException(
                    'Drupal.org project categories must use known Drupal.org category names.',
                );
            }
            $validatedCategories[] = $category;
        }
        $this->categories = $validatedCategories;

        $this->ecosystems = $this->validateTextList($ecosystems, 'ecosystems');
        $this->supportingOrganizations = $this->validateSupportingOrganizations($supportingOrganizations);
        $this->issueComponents = $this->validateTextList($issueComponents, 'issue components');
        $this->documentation = $this->validateLinks($documentation);
        $this->screenshots = $this->validateUrls($screenshots, 'screenshots');
        foreach ([
            'issue guidelines' => $this->issueGuidelines,
            'issue summary template' => $this->issueSummaryTemplate,
        ] as $field => $value) {
            if ($value !== '' && preg_match('/https?:\/\/|www\./i', $value) === 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Drupal.org project %s must not contain URLs.',
                    $field,
                ));
            }
        }
        foreach ([
            'homepage' => $this->homepage,
            'changelog' => $this->changelog,
            'demo' => $this->demo,
        ] as $field => $url) {
            $this->validateUrl($url, $field);
        }
    }

    /**
     * @return array{
     *     version: int,
     *     title: string,
     *     machineName: string,
     *     projectType: string,
     *     summary: string,
     *     descriptionHtml: string,
     *     maintenanceStatus: string,
     *     developmentStatus: string,
     *     categories: list<string>,
     *     ecosystems: list<string>,
     *     supportingOrganizations: list<array{name: string, howTheyHelped: string}>,
     *     issueComponents: list<string>,
     *     enableIssueTracker: bool,
     *     issueGuidelines: string,
     *     issueSummaryTemplate: string,
     *     homepage: string,
     *     changelog: string,
     *     demo: string,
     *     documentation: list<array{title: string, url: string}>,
     *     screenshots: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'title' => $this->title,
            'machineName' => $this->machineName,
            'projectType' => $this->projectType,
            'summary' => $this->summary,
            'descriptionHtml' => $this->descriptionHtml,
            'maintenanceStatus' => $this->maintenanceStatus,
            'developmentStatus' => $this->developmentStatus,
            'categories' => $this->categories,
            'ecosystems' => $this->ecosystems,
            'supportingOrganizations' => $this->supportingOrganizations,
            'issueComponents' => $this->issueComponents,
            'enableIssueTracker' => $this->enableIssueTracker,
            'issueGuidelines' => $this->issueGuidelines,
            'issueSummaryTemplate' => $this->issueSummaryTemplate,
            'homepage' => $this->homepage,
            'changelog' => $this->changelog,
            'demo' => $this->demo,
            'documentation' => $this->documentation,
            'screenshots' => $this->screenshots,
        ];
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return list<string>
     */
    private function validateTextList(array $values, string $field): array
    {
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException(sprintf('Drupal.org project %s must be a list.', $field));
        }

        $validated = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException(sprintf(
                    'Drupal.org project %s must contain non-empty strings.',
                    $field,
                ));
            }
            $validated[] = $value;
        }

        return $validated;
    }

    /**
     * @param array<int, mixed> $organizations
     *
     * @return list<array{name: string, howTheyHelped: string}>
     */
    private function validateSupportingOrganizations(array $organizations): array
    {
        if (!array_is_list($organizations)) {
            throw new \InvalidArgumentException('Drupal.org supporting organizations must be a list.');
        }

        $validated = [];
        foreach ($organizations as $organization) {
            if (!is_array($organization)
                || !is_string($organization['name'] ?? null)
                || !is_string($organization['howTheyHelped'] ?? null)
                || trim($organization['name']) === ''
                || trim($organization['howTheyHelped']) === ''
            ) {
                throw new \InvalidArgumentException(
                    'Drupal.org supporting organizations must contain a name and how they helped.',
                );
            }
            $validated[] = [
                'name' => $organization['name'],
                'howTheyHelped' => $organization['howTheyHelped'],
            ];
        }

        return $validated;
    }

    /**
     * @param array<int, mixed> $links
     *
     * @return list<array{title: string, url: string}>
     */
    private function validateLinks(array $links): array
    {
        if (!array_is_list($links)) {
            throw new \InvalidArgumentException('Drupal.org documentation must be a list.');
        }

        $validated = [];
        foreach ($links as $link) {
            if (!is_array($link)
                || !is_string($link['title'] ?? null)
                || !is_string($link['url'] ?? null)
                || trim($link['title']) === ''
            ) {
                throw new \InvalidArgumentException(
                    'Drupal.org documentation links must contain a title and URL.',
                );
            }
            $this->validateUrl($link['url'], 'documentation URL');
            $validated[] = ['title' => $link['title'], 'url' => $link['url']];
        }

        return $validated;
    }

    /**
     * @param array<int, mixed> $urls
     *
     * @return list<string>
     */
    private function validateUrls(array $urls, string $field): array
    {
        if (!array_is_list($urls)) {
            throw new \InvalidArgumentException(sprintf('Drupal.org project %s must be a list.', $field));
        }
        foreach ($urls as $url) {
            if (!is_string($url)) {
                throw new \InvalidArgumentException(sprintf('Drupal.org project %s must contain URLs.', $field));
            }
            $this->validateUrl($url, $field . ' URL');
        }

        return array_values($urls);
    }

    private function validateUrl(string $url, string $field): void
    {
        if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false
            || !preg_match('/^https?:\/\//i', $url))) {
            throw new \InvalidArgumentException(sprintf('Drupal.org project %s must be a valid HTTP URL.', $field));
        }
    }
}
