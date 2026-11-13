<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

final class CreateProjectTagResult implements ResultInterface
{
    public function __construct(public readonly string $tag, public readonly string $commitSha, public readonly bool $created)
    {
    }

    public function jsonSerialize(): mixed
    {
        return get_object_vars($this);
    }
}
