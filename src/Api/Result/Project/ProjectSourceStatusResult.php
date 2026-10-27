<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

final class ProjectSourceStatusResult implements ResultInterface
{
    public function __construct(
        public readonly string $localSourceCommitSha,
        public readonly string $localTreeSha,
        public readonly bool $branchExists,
        public readonly ?string $branchTreeSha,
        public readonly bool $branchMatchesSource,
        public readonly bool $tagExists,
        public readonly ?string $tagTreeSha,
        public readonly bool $tagMatchesSource,
        public readonly int $filesAdded = 0,
        public readonly int $filesModified = 0,
        public readonly int $filesDeleted = 0,
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return get_object_vars($this);
    }
}
