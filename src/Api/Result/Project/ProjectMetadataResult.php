<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

final class ProjectMetadataResult implements ResultInterface
{
    /**
     * @param list<string> $features
     * @param list<string> $integrations
     * @param array{input: ?int, output: ?int, total: ?int} $tokenUsage
     */
    public function __construct(
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
