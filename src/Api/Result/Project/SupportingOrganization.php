<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use JsonSerializable;

final class SupportingOrganization implements JsonSerializable
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

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
