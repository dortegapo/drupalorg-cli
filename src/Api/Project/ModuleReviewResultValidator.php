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
    public function validate(array $result, array $files, ?string $machineName = null): array
    {
        $lineCounts = $this->buildLineCounts($files);
        $primaryTerms = $this->validateSearchTerms($result['primary_search_terms'] ?? null, 2, 4);
        $secondaryTerms = $this->validateSearchTerms($result['secondary_search_terms'] ?? null, 0, 3);
        $this->validateNoOverlappingTerms($primaryTerms, $secondaryTerms);
        $functionalQueries = $this->validateFunctionalSearchQueries(
            $result['functional_search_queries'] ?? null,
            $machineName,
        );

        return [
            'purpose' => $this->requiredString($result['purpose'] ?? null),
            'capabilities' => $this->validateTextList($result['capabilities'] ?? null, true),
            'drupal_concepts' => $this->validateTextList($result['drupal_concepts'] ?? null),
            'integrations' => $this->validateTextList($result['integrations'] ?? null),
            'primary_search_terms' => $primaryTerms,
            'secondary_search_terms' => $secondaryTerms,
            'functional_search_queries' => $functionalQueries,
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
            if (preg_match('/^[a-z]+$/D', $term) !== 1 || in_array($term, ['client', 'clients'], true)) {
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
     * @return list<array{query: string, reason: string}>
     */
    private function validateFunctionalSearchQueries(mixed $value, ?string $machineName): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 2) {
            $this->invalid();
        }

        $normalizedMachineName = strtolower(trim((string) $machineName));
        $machineNameAsWords = str_replace('_', ' ', $normalizedMachineName);
        $queries = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                $this->invalid();
            }

            $query = $this->requiredString($item['query'] ?? null);
            $query = preg_replace('/\s+/u', ' ', $query) ?? $query;
            $reason = $this->requiredString($item['reason'] ?? null);
            if ($query !== strtolower($query)
                || strlen($query) > 80
                || preg_match('/(?:https?:\/\/|www\.)/i', $query) === 1
                || preg_match('/[?*\'"`]/', $query) === 1
            ) {
                $this->invalid();
            }

            $words = preg_split('/\s+/', $query);
            if (!is_array($words) || count($words) < 2 || count($words) > 4) {
                $this->invalid();
            }
            foreach ($words as $word) {
                if (in_array($word, ['and', 'or', 'not'], true)) {
                    $this->invalid();
                }
            }

            $queryKey = strtolower($query);
            if ($queryKey === $normalizedMachineName || $queryKey === $machineNameAsWords || isset($seen[$queryKey])) {
                $this->invalid();
            }

            $seen[$queryKey] = true;
            $queries[] = [
                'query' => $query,
                'reason' => $reason,
            ];
        }

        return $queries;
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
