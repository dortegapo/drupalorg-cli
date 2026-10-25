<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use ArrayAccess;
use mglaman\DrupalOrg\Result\ResultInterface;

/**
 * Structured result of the module review pipeline.
 *
 * The review schema is kept behind one boundary object while existing
 * renderers can consume its serialized representation.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class ModuleReviewResult implements ArrayAccess, ResultInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly array $data,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }

    public function isComplete(): bool
    {
        return ($this->data['complete'] ?? false) === true;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('ModuleReviewResult is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('ModuleReviewResult is immutable.');
    }
}
