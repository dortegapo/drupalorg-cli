<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use JsonSerializable;

/**
 * Source selected and prepared for an AI module review.
 *
 */
final class PreparedModuleSource implements JsonSerializable
{
    /**
     * @param list<array{path: string, bytes: int, content: string}> $files
     * @param list<array{path: string, reason: string}> $skipped
     * @param list<array{path: string, line: int, type: string}> $redactions
     */
    public function __construct(
        public readonly array $files,
        public readonly array $skipped,
        public readonly int $totalBytes,
        public readonly array $redactions,
        public readonly SourceCoverage $coverage,
    ) {
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        $files = $source['files'] ?? [];
        $totalBytes = $source['totalBytes'] ?? 0;
        $coverage = $source['coverage'] ?? [
            'mode' => 'full',
            'requestedBudgetBytes' => null,
            'eligibleFiles' => count($files),
            'eligibleBytes' => $totalBytes,
            'selectedFiles' => count($files),
            'selectedBytes' => $totalBytes,
            'omittedFiles' => 0,
            'omittedBytes' => 0,
            'omittedBreakdown' => [],
        ];

        return new self(
            files: $files,
            skipped: $source['skipped'] ?? [],
            totalBytes: $totalBytes,
            redactions: $source['redactions'] ?? [],
            coverage: $coverage instanceof SourceCoverage
                ? $coverage
                : SourceCoverage::fromArray($coverage),
        );
    }

    /** @param list<array{path: string, bytes: int, content: string}> $files */
    public function withSelection(
        array $files,
        int $totalBytes,
        SourceCoverage $coverage,
    ): self {
        return new self($files, $this->skipped, $totalBytes, $this->redactions, $coverage);
    }

    /**
     * @return array{
     *     files: list<array{path: string, bytes: int, content: string}>,
     *     skipped: list<array{path: string, reason: string}>,
     *     totalBytes: int,
     *     redactions: list<array{path: string, line: int, type: string}>,
     *     coverage: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'files' => $this->files,
            'skipped' => $this->skipped,
            'totalBytes' => $this->totalBytes,
            'redactions' => $this->redactions,
            'coverage' => $this->coverage->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
