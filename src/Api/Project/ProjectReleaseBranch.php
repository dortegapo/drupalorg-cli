<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/** A Drupal.org branch eligible for modern release tags. */
final class ProjectReleaseBranch
{
    private const PATTERN = '/^(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))?\.x$/';

    private function __construct(public readonly string $name)
    {
    }

    public static function fromString(string $name): self
    {
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid Drupal.org release branch "%s". Use 1.x or 1.2.x.',
                $name,
            ));
        }

        return new self($name);
    }
}
