<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class DrupalOrgProjectFormData
{
    /** @var list<string> */
    public readonly array $categories;

    public const VERSION = 1;

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
            if (!is_string($category) || trim($category) === '') {
                throw new \InvalidArgumentException(
                    'Drupal.org project categories must be non-empty strings.',
                );
            }
            $validatedCategories[] = $category;
        }
        $this->categories = $validatedCategories;
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
     *     categories: list<string>
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
        ];
    }
}
