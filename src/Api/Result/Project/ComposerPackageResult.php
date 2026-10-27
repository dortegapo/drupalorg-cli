<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

final class ComposerPackageResult implements ResultInterface
{
    public function __construct(public readonly string $package, public readonly string $version, public readonly bool $available)
    {
    }

    public function jsonSerialize(): mixed
    {
        return get_object_vars($this);
    }
}
