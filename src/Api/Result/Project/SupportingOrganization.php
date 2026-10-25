<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use ArrayAccess;
use JsonSerializable;
use LogicException;

/** @implements ArrayAccess<string, string> */
final class SupportingOrganization implements ArrayAccess, JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly string $howTheyHelped,
    ) {
    }

    /** @param array{name: string, howTheyHelped: string} $organization */
    public static function fromArray(array $organization): self
    {
        return new self($organization['name'], $organization['howTheyHelped']);
    }

    /** @return array{name: string, howTheyHelped: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'howTheyHelped' => $this->howTheyHelped,
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
        throw new LogicException('Supporting organization is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Supporting organization is immutable.');
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
