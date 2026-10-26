<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use JsonSerializable;

/**
 * Describes how much eligible module source was selected for an analysis.
 *
 */
final class SourceCoverage implements JsonSerializable
{
    /**
     * @param 'full'|'selected' $mode
     * @param array<string, int> $omittedBreakdown
     */
    public function __construct(
        public readonly string $mode,
        public readonly ?int $requestedBudgetBytes,
        public readonly int $eligibleFiles,
        public readonly int $eligibleBytes,
        public readonly int $selectedFiles,
        public readonly int $selectedBytes,
        public readonly int $omittedFiles,
        public readonly int $omittedBytes,
        public readonly array $omittedBreakdown,
    ) {
        if (!in_array($mode, ['full', 'selected'], true)) {
            throw new \InvalidArgumentException('Source coverage mode must be full or selected.');
        }
    }

    /**
     * @param array<string, mixed> $coverage
     */
    public static function fromArray(array $coverage): self
    {
        return new self(
            mode: $coverage['mode'] ?? 'full',
            requestedBudgetBytes: $coverage['requestedBudgetBytes'] ?? null,
            eligibleFiles: $coverage['eligibleFiles'] ?? 0,
            eligibleBytes: $coverage['eligibleBytes'] ?? 0,
            selectedFiles: $coverage['selectedFiles'] ?? 0,
            selectedBytes: $coverage['selectedBytes'] ?? 0,
            omittedFiles: $coverage['omittedFiles'] ?? 0,
            omittedBytes: $coverage['omittedBytes'] ?? 0,
            omittedBreakdown: $coverage['omittedBreakdown'] ?? [],
        );
    }

    /**
     * @return array{
     *     mode: 'full'|'selected',
     *     requestedBudgetBytes: ?int,
     *     eligibleFiles: int,
     *     eligibleBytes: int,
     *     selectedFiles: int,
     *     selectedBytes: int,
     *     omittedFiles: int,
     *     omittedBytes: int,
     *     omittedBreakdown: array<string, int>
     * }
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'requestedBudgetBytes' => $this->requestedBudgetBytes,
            'eligibleFiles' => $this->eligibleFiles,
            'eligibleBytes' => $this->eligibleBytes,
            'selectedFiles' => $this->selectedFiles,
            'selectedBytes' => $this->selectedBytes,
            'omittedFiles' => $this->omittedFiles,
            'omittedBytes' => $this->omittedBytes,
            'omittedBreakdown' => $this->omittedBreakdown,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
