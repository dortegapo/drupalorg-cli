<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

/**
 * Typed internal result of the module review pipeline.
 *
 * Serialization is deliberately kept at the command/report boundary through
 * toArray(); the pipeline itself consumes explicit top-level properties.
 */
final class ModuleReviewResult
{
    /** @var array{machineName: string, path: string} */
    public readonly array $module;

    /** @var array<string, mixed> */
    public readonly array $source;

    /** @var array<string, mixed>|null */
    public readonly ?array $review;

    /** @var list<array<string, mixed>> */
    public readonly array $searches;

    /** @var list<array<string, mixed>> */
    public readonly array $candidates;

    /** @var array<string, mixed> */
    public readonly array $discovery;

    /** @var array<string, mixed>|null */
    public readonly ?array $comparison;

    /** @var array<string, mixed>|null */
    public readonly ?array $assessment;

    /** @var array<string, mixed>|null */
    public readonly ?array $core;

    /** @var array<string, mixed> */
    public readonly array $candidateCompatibility;

    /** @var array<string, mixed> */
    public readonly array $ai;

    /** @var list<array{stage: string, message: string}> */
    public readonly array $limitations;

    /**
     * @param array<string, mixed> $module
     * @param array<string, mixed> $source
     * @param array<string, mixed>|null $review
     * @param list<array<string, mixed>> $searches
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $discovery
     * @param array<string, mixed>|null $comparison
     * @param array<string, mixed>|null $assessment
     * @param array<string, mixed>|null $core
     * @param array<string, mixed> $candidateCompatibility
     * @param array<string, mixed> $ai
     * @param list<array<string, mixed>> $limitations
     */
    public function __construct(
        public readonly bool $complete,
        array $module,
        array $source,
        ?array $review,
        array $searches,
        array $candidates,
        array $discovery,
        ?array $comparison,
        ?array $assessment,
        ?array $core,
        array $candidateCompatibility,
        array $ai,
        array $limitations,
    ) {
        $this->module = $module;
        $this->source = $source;
        $this->review = $review;
        $this->searches = $searches;
        $this->candidates = $candidates;
        $this->discovery = $discovery;
        $this->comparison = $comparison;
        $this->assessment = $assessment;
        $this->core = $core;
        $this->candidateCompatibility = $candidateCompatibility;
        $this->ai = $ai;
        $this->limitations = $limitations;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            complete: ($data['complete'] ?? false) === true,
            module: is_array($data['module'] ?? null) ? $data['module'] : [],
            source: is_array($data['source'] ?? null) ? $data['source'] : [],
            review: is_array($data['review'] ?? null) ? $data['review'] : null,
            searches: is_array($data['searches'] ?? null) ? $data['searches'] : [],
            candidates: is_array($data['candidates'] ?? null) ? $data['candidates'] : [],
            discovery: is_array($data['discovery'] ?? null) ? $data['discovery'] : [],
            comparison: is_array($data['comparison'] ?? null) ? $data['comparison'] : null,
            assessment: is_array($data['assessment'] ?? null) ? $data['assessment'] : null,
            core: is_array($data['core'] ?? null) ? $data['core'] : null,
            candidateCompatibility: is_array($data['candidateCompatibility'] ?? null)
                ? $data['candidateCompatibility']
                : [],
            ai: is_array($data['ai'] ?? null) ? $data['ai'] : [],
            limitations: is_array($data['limitations'] ?? null) ? $data['limitations'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'complete' => $this->complete,
            'module' => $this->module,
            'source' => $this->source,
            'review' => $this->review,
            'searches' => $this->searches,
            'candidates' => $this->candidates,
            'discovery' => $this->discovery,
            'comparison' => $this->comparison,
            'assessment' => $this->assessment,
            'ai' => $this->ai,
            'limitations' => $this->limitations,
        ];
        if ($this->core !== null) {
            $data['core'] = $this->core;
            $data['candidateCompatibility'] = $this->candidateCompatibility;
        }

        return $data;
    }
}
