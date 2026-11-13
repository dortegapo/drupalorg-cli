<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

final class PublishProjectSourceResult implements ResultInterface
{
    public function __construct(
        public readonly string $branch,
        public readonly string $sourceCommitSha,
        public readonly string $commitSha,
        public readonly int $filesChanged,
        public readonly bool $created,
        public readonly bool $alreadyUpToDate,
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return get_object_vars($this);
    }
}
