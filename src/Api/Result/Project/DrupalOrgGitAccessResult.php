<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use mglaman\DrupalOrg\Result\ResultInterface;

/** Result of one non-interactive Drupal.org Git SSH access check. */
final class DrupalOrgGitAccessResult implements ResultInterface
{
    public const AUTHENTICATED = 'authenticated';

    public const AUTHENTICATION_MISSING = 'authentication_missing';

    public const HOST_KEY_FAILURE = 'host_key_failure';

    public const NETWORK_FAILURE = 'network_failure';

    public const SSH_UNAVAILABLE = 'ssh_unavailable';

    public const UNKNOWN_FAILURE = 'unknown_failure';

    public function __construct(
        public readonly string $status,
        public readonly ?string $username = null,
        public readonly ?string $diagnostic = null,
    ) {
    }

    public function authenticated(): bool
    {
        return $this->status === self::AUTHENTICATED;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'status' => $this->status,
            'username' => $this->username,
        ];
    }
}
