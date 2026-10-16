<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class ModuleReviewResultValidator
{
    private const INVALID_RESULT_MESSAGE = 'Drupal AI module review returned an invalid result.';

    /**
     * @param array<string, mixed> $result
     * @param list<array{path: string, bytes: int, content: string}> $files
     *
     * @return array<string, mixed>
     */
    public function validate(array $result, array $files): array
    {
        $lineCounts = $this->buildLineCounts($files);
        $primaryTerms = $this->validateSearchTerms($result['primary_search_terms'] ?? null, 1, 5);
        $secondaryTerms = $this->validateSearchTerms($result['secondary_search_terms'] ?? null, 0, 5);
        $this->validateNoOverlappingTerms($primaryTerms, $secondaryTerms);

        return [
            'purpose' => $this->requiredString($result['purpose'] ?? null),
            'capabilities' => $this->validateTextList($result['capabilities'] ?? null, true),
            'drupal_concepts' => $this->validateTextList($result['drupal_concepts'] ?? null),
            'integrations' => $this->validateTextList($result['integrations'] ?? null),
            'primary_search_terms' => $primaryTerms,
            'secondary_search_terms' => $secondaryTerms,
            'reusability' => $this->validateReusability($result['reusability'] ?? null, $lineCounts),
            'contrib_readiness' => $this->validateContribReadiness(
                $result['contrib_readiness'] ?? null,
                $lineCounts,
            ),
            'analysis_limits' => $this->validateTextList($result['analysis_limits'] ?? null),
        ];
    }

    /**
     * @param list<array{path: string, bytes: int, content: string}> $files
     *
     * @return array<string, int>
     */
    private function buildLineCounts(array $files): array
    {
        $lineCounts = [];
        foreach ($files as $file) {
            $lineCounts[$file['path']] = $this->lineCount($file['content']);
        }

        return $lineCounts;
    }

    /**
     * @return list<array{term: string, reason: string}>
     */
    private function validateSearchTerms(mixed $value, int $minimum, int $maximum): array
    {
        if (!is_array($value) || count($value) < $minimum || count($value) > $maximum) {
            $this->invalid();
        }

        $terms = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                $this->invalid();
            }

            $term = $this->requiredString($item['term'] ?? null);
            $reason = $this->requiredString($item['reason'] ?? null);
            if (preg_match('/^[a-z]+$/D', $term) !== 1 || $term === 'client') {
                $this->invalid();
            }

            if (isset($seen[$term])) {
                $this->invalid();
            }

            $seen[$term] = true;
            $terms[] = [
                'term' => $term,
                'reason' => $reason,
            ];
        }

        return $terms;
    }

    /**
     * @param list<array{term: string, reason: string}> $primaryTerms
     * @param list<array{term: string, reason: string}> $secondaryTerms
     */
    private function validateNoOverlappingTerms(array $primaryTerms, array $secondaryTerms): void
    {
        $primary = [];
        foreach ($primaryTerms as $term) {
            $primary[$term['term']] = true;
        }

        foreach ($secondaryTerms as $term) {
            if (isset($primary[$term['term']])) {
                $this->invalid();
            }
        }
    }

    /**
     * @param array<string, int> $lineCounts
     *
     * @return array{status: string, confidence: int|float, findings: list<array<string, mixed>>}
     */
    private function validateReusability(mixed $value, array $lineCounts): array
    {
        if (!is_array($value)) {
            $this->invalid();
        }

        $status = $value['status'] ?? null;
        if (!is_string($status) || !in_array(
            $status,
            ['reusable', 'needs_generalization', 'client_specific', 'unclear'],
            true,
        )) {
            $this->invalid();
        }

        $confidence = $value['confidence'] ?? null;
        if ((!is_int($confidence) && !is_float($confidence))
            || !is_finite((float) $confidence)
            || $confidence < 0
            || $confidence > 1
        ) {
            $this->invalid();
        }

        return [
            'status' => $status,
            'confidence' => $confidence,
            'findings' => $this->validateFindings($value['findings'] ?? null, $lineCounts),
        ];
    }

    /**
     * @param array<string, int> $lineCounts
     *
     * @return array{status: string, findings: list<array<string, mixed>>}
     */
    private function validateContribReadiness(mixed $value, array $lineCounts): array
    {
        if (!is_array($value)) {
            $this->invalid();
        }

        $status = $value['status'] ?? null;
        if (!is_string($status) || !in_array(
            $status,
            ['ready', 'needs_changes', 'significant_changes', 'unclear'],
            true,
        )) {
            $this->invalid();
        }

        return [
            'status' => $status,
            'findings' => $this->validateFindings($value['findings'] ?? null, $lineCounts),
        ];
    }

    /**
     * @param array<string, int> $lineCounts
     *
     * @return list<array<string, mixed>>
     */
    private function validateFindings(mixed $value, array $lineCounts): array
    {
        if (!is_array($value)) {
            $this->invalid();
        }

        $findings = [];
        foreach ($value as $finding) {
            if (!is_array($finding)) {
                $this->invalid();
            }

            $severity = $finding['severity'] ?? null;
            if (!is_string($severity) || !in_array($severity, ['high', 'medium', 'low'], true)) {
                $this->invalid();
            }

            $file = $this->requiredString($finding['file'] ?? null);
            if (!array_key_exists($file, $lineCounts)) {
                $this->invalid();
            }

            $line = $finding['line'] ?? null;
            if (!is_int($line) || $line < 1 || $line > $lineCounts[$file]) {
                $this->invalid();
            }

            $findings[] = [
                'severity' => $severity,
                'file' => $file,
                'line' => $line,
                'evidence' => $this->requiredString($finding['evidence'] ?? null),
                'reason' => $this->requiredString($finding['reason'] ?? null),
                'suggestion' => $this->requiredString($finding['suggestion'] ?? null),
            ];
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function validateTextList(mixed $value, bool $requireItem = false): array
    {
        if (!is_array($value) || ($requireItem && $value === [])) {
            $this->invalid();
        }

        $normalized = [];
        foreach ($value as $item) {
            $normalized[] = $this->requiredString($item);
        }

        return $normalized;
    }

    private function requiredString(mixed $value): string
    {
        if (!is_string($value)) {
            $this->invalid();
        }

        $value = trim($value);
        if ($value === '') {
            $this->invalid();
        }

        return $value;
    }

    private function lineCount(string $content): int
    {
        $normalizedContent = str_replace(["\r\n", "\r"], "\n", $content);
        if ($normalizedContent === '') {
            return 0;
        }

        $lines = explode("\n", $normalizedContent);
        if (str_ends_with($normalizedContent, "\n")) {
            array_pop($lines);
        }

        return count($lines);
    }

    private function invalid(): never
    {
        throw new \RuntimeException(self::INVALID_RESULT_MESSAGE);
    }
}
