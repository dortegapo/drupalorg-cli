<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/** A modern Drupal.org release tag. */
final class ProjectReleaseVersion
{
    private const PATTERN = '/^(?<major>0|[1-9][0-9]*)\.(?<minor>0|[1-9][0-9]*)\.(?<patch>0|[1-9][0-9]*)(?:-(?<stability>alpha|beta|rc)(?<number>[1-9][0-9]*))?$/';

    private function __construct(
        public readonly string $value,
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly ?string $stability,
        public readonly ?int $prereleaseNumber,
    ) {
    }

    public static function fromString(string $value): self
    {
        $matches = [];
        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid Drupal.org release version "%s". Use 1.0.0 or 1.0.0-beta1.',
                $value,
            ));
        }

        return new self(
            $value,
            (int) $matches['major'],
            (int) $matches['minor'],
            (int) $matches['patch'],
            $matches['stability'] ?? null,
            isset($matches['number']) ? (int) $matches['number'] : null,
        );
    }

    public function isStable(): bool
    {
        return $this->stability === null;
    }

    public function suggestedBranch(): ProjectReleaseBranch
    {
        return ProjectReleaseBranch::fromString($this->major . '.' . $this->minor . '.x');
    }
}
