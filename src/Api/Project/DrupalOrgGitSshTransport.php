<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/** The SSH transport selected by Drupal.org Git preflight. */
final class DrupalOrgGitSshTransport
{
    public function __construct(public readonly string $identityFile)
    {
        if ($this->identityFile === '' || str_contains($this->identityFile, "\0")) {
            throw new \InvalidArgumentException('An SSH identity file is required.');
        }
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return [
            'GIT_SSH_COMMAND' => sprintf(
                'ssh -o BatchMode=yes -o IdentitiesOnly=yes -i %s',
                escapeshellarg($this->identityFile),
            ),
        ];
    }
}
