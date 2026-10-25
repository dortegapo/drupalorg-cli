<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use ArrayAccess;

/**
 * Physical module location resolved by ModuleLocator.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class ResolvedModule implements ArrayAccess
{
    public function __construct(
        public readonly string $resolvedPath,
        public readonly string $machineName,
        public readonly string $infoFile,
        public readonly ?string $projectRoot,
        public readonly ?string $webRoot,
    ) {
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['resolvedPath', 'moduleName', 'infoFile', 'projectRoot', 'webRoot'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'resolvedPath' => $this->resolvedPath,
            'moduleName' => $this->machineName,
            'infoFile' => $this->infoFile,
            'projectRoot' => $this->projectRoot,
            'webRoot' => $this->webRoot,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('ResolvedModule is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('ResolvedModule is immutable.');
    }
}
