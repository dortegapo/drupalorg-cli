<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

final class ProjectMetadataResult implements ResultInterface
{
    /** @var list<SupportingOrganization> */
    public readonly array $supportingOrganizations;

    public readonly ProjectIssueSettings $issues;

    public readonly ProjectResources $resources;

    /**
     * @param list<string> $dependencies
     * @param list<string> $features
     * @param list<string> $integrations
     * @param list<string> $categories
     * @param list<string> $ecosystems
     * @param list<array{name: string, howTheyHelped: string}|SupportingOrganization> $supportingOrganizations
     * @param array<string, mixed>|ProjectIssueSettings $issues
     * @param array<string, mixed>|ProjectResources $resources
     * @param array{input: ?int, output: ?int, total: ?int} $tokenUsage
     */
    public function __construct(
        public readonly string $title,
        public readonly string $machineName,
        public readonly string $projectType,
        public readonly string $coreVersionRequirement,
        public readonly array $dependencies,
        public readonly string $summary,
        public readonly string $description,
        public readonly array $features,
        public readonly array $integrations,
        public readonly string $providerId,
        public readonly string $modelId,
        public readonly array $tokenUsage,
        public readonly array $categories = [],
        public readonly array $ecosystems = [],
        array $supportingOrganizations = [],
        array|ProjectIssueSettings $issues = [],
        array|ProjectResources $resources = [],
    ) {
        $this->supportingOrganizations = array_map(
            static fn(array|SupportingOrganization $organization): SupportingOrganization => $organization instanceof SupportingOrganization
                ? $organization
                : SupportingOrganization::fromArray($organization),
            $supportingOrganizations,
        );
        $this->issues = $issues instanceof ProjectIssueSettings
            ? $issues
            : ProjectIssueSettings::fromArray($issues);
        $this->resources = $resources instanceof ProjectResources
            ? $resources
            : ProjectResources::fromArray($resources);
    }

    public function jsonSerialize(): mixed
    {
        return [
            'title' => $this->title,
            'machine_name' => $this->machineName,
            'project_type' => $this->projectType,
            'core_version_requirement' => $this->coreVersionRequirement,
            'dependencies' => $this->dependencies,
            'summary' => $this->summary,
            'description' => $this->description,
            'features' => $this->features,
            'integrations' => $this->integrations,
            'provider_id' => $this->providerId,
            'model_id' => $this->modelId,
            'token_usage' => $this->tokenUsage,
            'categories' => $this->categories,
            'ecosystems' => $this->ecosystems,
            'supporting_organizations' => array_map(
                static fn(SupportingOrganization $organization): array => $organization->toArray(),
                $this->supportingOrganizations,
            ),
            'issues' => $this->issues->toArray(),
            'resources' => $this->resources->jsonSerialize(),
        ];
    }
}
