<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

final class ComposerPackageResult implements ResultInterface
{
    public const AVAILABLE = 'available';

    public const PACKAGE_OR_VERSION_PENDING = 'package_or_version_pending';

    public const COMPOSER_UNAVAILABLE = 'composer_unavailable';

    public const NETWORK_OR_REPOSITORY_FAILURE = 'network_or_repository_failure';

    public function __construct(
        public readonly string $package,
        public readonly string $version,
        public readonly string $status,
    ) {
    }

    public function available(): bool
    {
        return $this->status === self::AVAILABLE;
    }

    public function jsonSerialize(): mixed
    {
        return get_object_vars($this) + ['available' => $this->available()];
    }
}
