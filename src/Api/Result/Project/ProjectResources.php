<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use JsonSerializable;

final class ProjectResources implements JsonSerializable
{
    /**
     * @param list<DocumentationLink> $documentation
     * @param list<string> $screenshots
     */
    public function __construct(
        public readonly string $homepage = '',
        public readonly string $changelog = '',
        public readonly string $demo = '',
        public readonly array $documentation = [],
        public readonly array $screenshots = [],
    ) {
    }

    /** @param array<string, mixed> $resources */
    public static function fromArray(array $resources): self
    {
        return new self(
            homepage: $resources['homepage'] ?? '',
            changelog: $resources['changelog'] ?? '',
            demo: $resources['demo'] ?? '',
            documentation: array_map(
                static fn(DocumentationLink|array $link): DocumentationLink => $link instanceof DocumentationLink
                    ? $link
                    : DocumentationLink::fromArray($link),
                $resources['documentation'] ?? [],
            ),
            screenshots: $resources['screenshots'] ?? [],
        );
    }

    /** @return array{homepage: string, changelog: string, demo: string, documentation: list<DocumentationLink>, screenshots: list<string>} */
    public function toArray(): array
    {
        return [
            'homepage' => $this->homepage,
            'changelog' => $this->changelog,
            'demo' => $this->demo,
            'documentation' => $this->documentation,
            'screenshots' => $this->screenshots,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'homepage' => $this->homepage,
            'changelog' => $this->changelog,
            'demo' => $this->demo,
            'documentation' => array_map(
                static fn(DocumentationLink $link): array => $link->toArray(),
                $this->documentation,
            ),
            'screenshots' => $this->screenshots,
        ];
    }
}
