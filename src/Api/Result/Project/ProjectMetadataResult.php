<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

final class ProjectMetadataResult implements ResultInterface
{
    /**
     * @param list<string> $dependencies
     * @param list<string> $features
     * @param list<string> $integrations
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
    ) {
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
        ];
    }
}
