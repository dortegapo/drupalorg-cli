<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use JsonSerializable;

final class DocumentationLink implements JsonSerializable
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

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
