<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use JsonSerializable;

/**
 * Validated semantic comparison of Drupal.org candidates.
 *
 */
final class ProjectComparisonResult implements JsonSerializable
{
    /**
     * @param 'no_clear_duplicate'|'possible_overlap'|'likely_duplicate' $verdict
     * @param list<array<string, mixed>> $candidates
     */
    public function __construct(
        public readonly string $verdict,
        public readonly int|float $confidence,
        public readonly string $summary,
        public readonly array $candidates,
    ) {
    }

    /** @return array{verdict: string, confidence: int|float, summary: string, candidates: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'confidence' => $this->confidence,
            'summary' => $this->summary,
            'candidates' => $this->candidates,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
