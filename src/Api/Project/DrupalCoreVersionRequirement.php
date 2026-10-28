<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

/** Resolves a Drupal core Composer constraint to the major versions it covers. */
final class DrupalCoreVersionRequirement
{
    private const FIRST_MAJOR = 1;

    private const LAST_MAJOR = 99;

    /**
     * @return list<int>
     */
    public static function supportedMajors(string $constraint): array
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            throw new \InvalidArgumentException('Drupal core version requirement cannot be empty.');
        }

        try {
            $parsedConstraint = (new VersionParser())->parseConstraints($constraint);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException(
                sprintf('Invalid Drupal core version requirement: %s.', $constraint),
                0,
                $exception,
            );
        }

        $majors = [];
        for ($major = self::FIRST_MAJOR; $major <= self::LAST_MAJOR; ++$major) {
            $majorConstraint = (new VersionParser())->parseConstraints(sprintf(
                '>=%d.0.0 <%d.0.0',
                $major,
                $major + 1,
            ));
            if (Intervals::haveIntersections($parsedConstraint, $majorConstraint)) {
                $majors[] = $major;
            }
        }

        if ($majors === []) {
            throw new \InvalidArgumentException(
                sprintf('Drupal core version requirement does not select a supported major: %s.', $constraint),
            );
        }

        return $majors;
    }
}
