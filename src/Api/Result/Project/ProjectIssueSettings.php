<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Result\Project;

use ArrayAccess;
use JsonSerializable;
use LogicException;

/** @implements ArrayAccess<string, mixed> */
final class ProjectIssueSettings implements ArrayAccess, JsonSerializable
{
    /** @param list<string> $components */
    public function __construct(
        public readonly array $components = [],
        public readonly string $submissionGuidelines = '',
        public readonly string $summaryTemplate = '',
        public readonly bool $enableTracker = true,
    ) {
    }

    /** @param array<string, mixed> $settings */
    public static function fromArray(array $settings): self
    {
        return new self(
            components: $settings['components'] ?? [],
            submissionGuidelines: $settings['submissionGuidelines'] ?? '',
            summaryTemplate: $settings['summaryTemplate'] ?? '',
            enableTracker: $settings['enableTracker'] ?? true,
        );
    }

    /** @return array{components: list<string>, submissionGuidelines: string, summaryTemplate: string, enableTracker: bool} */
    public function toArray(): array
    {
        return [
            'components' => $this->components,
            'submissionGuidelines' => $this->submissionGuidelines,
            'summaryTemplate' => $this->summaryTemplate,
            'enableTracker' => $this->enableTracker,
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
        throw new LogicException('Project issue settings are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Project issue settings are immutable.');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
