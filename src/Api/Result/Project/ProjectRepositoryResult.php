<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

final class ProjectRepositoryResult implements ResultInterface
{
    /**
     * @param array<string, string> $branches
     * @param array<string, string> $tags
     */
    public function __construct(
        public readonly string $url,
        public readonly bool $reachable,
        public readonly array $branches = [],
        public readonly array $tags = [],
        public readonly ?string $failure = null,
    ) {
    }

    public function branchSha(string $branch): ?string
    {
        return $this->branches[$branch] ?? null;
    }

    public function tagSha(string $tag): ?string
    {
        return $this->tags[$tag] ?? null;
    }

    public function jsonSerialize(): mixed
    {
        return ['url' => $this->url, 'reachable' => $this->reachable, 'branches' => $this->branches, 'tags' => $this->tags, 'failure' => $this->failure];
    }
}
