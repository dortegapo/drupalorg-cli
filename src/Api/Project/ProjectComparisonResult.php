<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use ArrayAccess;
use JsonSerializable;
use LogicException;

/**
 * Validated semantic comparison of Drupal.org candidates.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class ProjectComparisonResult implements ArrayAccess, JsonSerializable
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

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Project comparison result is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Project comparison result is immutable.');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
