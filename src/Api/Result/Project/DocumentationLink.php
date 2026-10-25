<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use ArrayAccess;
use JsonSerializable;
use LogicException;

/** @implements ArrayAccess<string, string> */
final class DocumentationLink implements ArrayAccess, JsonSerializable
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
    ) {
    }

    /** @param array{title: string, url: string} $link */
    public static function fromArray(array $link): self
    {
        return new self($link['title'], $link['url']);
    }

    /** @return array{title: string, url: string} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'url' => $this->url];
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
        throw new LogicException('Documentation link is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Documentation link is immutable.');
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
