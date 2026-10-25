<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use ArrayAccess;

/**
 * Validated metadata read from a module's .info.yml file.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class ModuleInfo implements ArrayAccess
{
    /**
     * @param list<string> $dependencies
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $coreVersionRequirement,
        public readonly array $dependencies,
    ) {
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['name', 'type', 'coreVersionRequirement', 'dependencies'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'name' => $this->name,
            'type' => $this->type,
            'coreVersionRequirement' => $this->coreVersionRequirement,
            'dependencies' => $this->dependencies,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('ModuleInfo is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('ModuleInfo is immutable.');
    }
}
