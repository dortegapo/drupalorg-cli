<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use Dompdf\Dompdf;
use Dompdf\Options;

final class ModuleReviewPdfReportWriter
{
    /**
     * @param array<string, mixed> $analysis
     * @param array{
     *     machineName: string,
     *     name: string,
     *     type: string,
     *     coreVersionRequirement: string
     * } $moduleInfo
     */
    public function write(string $targetPath, array $analysis, array $moduleInfo): void
    {
        if (trim($targetPath) === '') {
            throw new \InvalidArgumentException('The PDF report target path cannot be empty.');
        }

        try {
            $options = new Options();
            $options->setDefaultFont('DejaVu Sans');
            $options->setIsJavascriptEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setIsRemoteEnabled(false);

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($this->buildHtml($analysis, $moduleInfo), 'UTF-8');
            $dompdf->setPaper('A4');
            $dompdf->render();
            $this->writeAtomically($targetPath, $dompdf->output());
        } catch (\InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Could not generate PDF review report.', 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array{
     *     machineName: string,
     *     name: string,
     *     type: string,
     *     coreVersionRequirement: string
     * } $moduleInfo
     */
    private function buildHtml(array $analysis, array $moduleInfo): string
    {
        $machineName = $this->escape($moduleInfo['machineName']);
        $html = '<!doctype html><html><head><meta charset="UTF-8">'
            . '<style>' . $this->styles() . '</style></head><body>';
        $html .= '<h1>Drupal.org Contribution Review</h1>';
        $html .= '<p class="subtitle">' . $machineName . '</p>';
        $html .= '<p class="notice">AI-assisted, advisory report</p>';
        $html .= $this->moduleSection($moduleInfo);
        $html .= $this->coreSection($analysis);
        $html .= $this->assessmentSection($analysis);

        $review = is_array($analysis['review'] ?? null) ? $analysis['review'] : null;
        if ($review !== null) {
            $html .= $this->reviewSection($review);
        }

        $html .= $this->discoverySection($analysis);

        $comparison = is_array($analysis['comparison'] ?? null) ? $analysis['comparison'] : null;
        if ($comparison !== null) {
            $html .= $this->comparisonSection($analysis, $comparison);
        }

        $html .= $this->sourceSection($analysis);
        $html .= $this->limitationsSection($analysis, $review);
        $html .= '</body></html>';

        return $html;
    }

    /**
     * @param array{
     *     machineName: string,
     *     name: string,
     *     type: string,
     *     coreVersionRequirement: string
     * } $moduleInfo
     */
    private function moduleSection(array $moduleInfo): string
    {
        return '<section><h2>Module</h2><dl>'
            . $this->definition('Machine name', $moduleInfo['machineName'])
            . $this->definition('Human-readable name', $moduleInfo['name'])
            . $this->definition('Type', $moduleInfo['type'])
            . $this->definition('Core version requirement', $moduleInfo['coreVersionRequirement'])
            . '</dl></section>';
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function coreSection(array $analysis): string
    {
        $core = is_array($analysis['core'] ?? null) ? $analysis['core'] : [];
        $version = is_string($core['runtimeVersion'] ?? null) ? $core['runtimeVersion'] : null;
        $major = is_int($core['targetMajor'] ?? null) ? $core['targetMajor'] : null;
        if ($version === null || $major === null) {
            return '<section><h2>Target Drupal core</h2><p>Unknown</p></section>';
        }

        return '<section><h2>Target Drupal core</h2><dl>'
            . $this->definition('Runtime', 'Drupal ' . $version)
            . $this->definition('Target major', 'Drupal ' . $major)
            . '</dl></section>';
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function assessmentSection(array $analysis): string
    {
        if (($analysis['complete'] ?? false) !== true) {
            $limitation = is_array($analysis['limitations'][0] ?? null)
                ? $analysis['limitations'][0]
                : [];
            $stage = is_string($limitation['stage'] ?? null) ? $limitation['stage'] : 'unknown';
            $message = is_string($limitation['message'] ?? null)
                ? $limitation['message']
                : 'The AI-assisted contribution review could not be completed.';

            return '<section><h2>Executive assessment</h2>'
                . '<p><strong>Review status:</strong> Incomplete</p>'
                . '<p><strong>Failed stage:</strong> ' . $this->escape($stage) . '</p>'
                . '<p>' . $this->escape($message) . '</p></section>';
        }

        $assessment = is_array($analysis['assessment'] ?? null) ? $analysis['assessment'] : [];
        $status = is_string($assessment['status'] ?? null) ? $assessment['status'] : 'unknown';
        $titlesByAssessment = [
            'likely_duplicate' => [],
            'possible_overlap' => [],
        ];
        $comparison = is_array($analysis['comparison'] ?? null) ? $analysis['comparison'] : [];
        $comparisonCandidates = is_array($comparison['candidates'] ?? null)
            ? $comparison['candidates']
            : [];
        $candidateMap = $this->candidateMap($analysis['candidates'] ?? []);
        foreach ($comparisonCandidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $assessmentValue = $candidate['assessment'] ?? null;
            if (!is_string($assessmentValue)
                || !in_array($assessmentValue, ['likely_duplicate', 'possible_overlap'], true)
            ) {
                continue;
            }

            $machineName = $this->stringValue($candidate['machine_name'] ?? 'Unavailable');
            $poolCandidate = $candidateMap[$machineName] ?? [];
            $title = $this->stringValue($poolCandidate['title'] ?? $machineName);
            $titlesByAssessment[$assessmentValue][] = $title;
        }
        $review = is_array($analysis['review'] ?? null) ? $analysis['review'] : [];
        $reusability = is_array($review['reusability'] ?? null) ? $review['reusability'] : [];
        $readiness = is_array($review['contrib_readiness'] ?? null) ? $review['contrib_readiness'] : [];

        $html = '<section><h2>Executive assessment</h2>'
            . '<p><strong>Recommendation:</strong> ' . $this->statusBadge($this->label($status), $this->statusSemanticType($status)) . '</p>'
            . '<h3>Main signals</h3><ul>';
        if ($titlesByAssessment['likely_duplicate'] !== []) {
            $titles = [];
            foreach (array_slice($titlesByAssessment['likely_duplicate'], 0, 3) as $title) {
                $titles[] = $this->escape($title);
            }
            $count = count($titlesByAssessment['likely_duplicate']);
            $html .= '<li><strong>' . $count . ' likely functional duplicate'
                . ($count === 1 ? '' : 's') . ' identified</strong><br>'
                . '<span class="meta">Likely duplicate candidates: ' . implode(', ', $titles) . '</span></li>';
        }
        if ($titlesByAssessment['possible_overlap'] !== []) {
            $count = count($titlesByAssessment['possible_overlap']);
            $html .= '<li>' . $count . ' project' . ($count === 1 ? '' : 's')
                . ' with possible functional overlap</li>';
        }
        $compatibilitySummary = $this->executiveCompatibilitySummary($analysis, $comparisonCandidates, $candidateMap);
        if ($compatibilitySummary !== '') {
            $html .= '<li><span class="meta">' . $compatibilitySummary . '</span></li>';
        }
        if ($titlesByAssessment['likely_duplicate'] === [] && $titlesByAssessment['possible_overlap'] === []) {
            $html .= '<li>No likely duplicate or potential functional overlap was identified.</li>';
        }
        $reusabilityStatus = is_string($reusability['status'] ?? null) ? $reusability['status'] : 'unknown';
        $readinessStatus = is_string($readiness['status'] ?? null) ? $readiness['status'] : 'unknown';
        $html .= '<li>Reusability: ' . $this->statusBadge($this->label($reusabilityStatus), $this->statusSemanticType($reusabilityStatus)) . '</li>'
            . '<li>Contribution readiness: ' . $this->statusBadge($this->label($readinessStatus), $this->statusSemanticType($readinessStatus)) . '</li>'
            . '</ul><p>This assessment is advisory only. It does not prevent you from continuing to publish.</p></section>';

        return $html;
    }

    /**
     * @param array<string, mixed> $analysis
     * @param list<mixed> $comparisonCandidates
     * @param array<string, array<string, mixed>> $candidateMap
     */
    private function executiveCompatibilitySummary(
        array $analysis,
        array $comparisonCandidates,
        array $candidateMap,
    ): string {
        $compatibilities = is_array($analysis['candidateCompatibility'] ?? null)
            ? $analysis['candidateCompatibility']
            : [];
        if ($compatibilities === []) {
            return '';
        }

        $items = [];
        foreach (array_slice($comparisonCandidates, 0, 3) as $candidate) {
            if (!is_array($candidate) || !is_string($candidate['machine_name'] ?? null)) {
                continue;
            }
            $machineName = $candidate['machine_name'];
            $compatibility = is_array($compatibilities[$machineName] ?? null)
                ? $compatibilities[$machineName]
                : [];
            $status = is_string($compatibility['status'] ?? null) ? $compatibility['status'] : 'unknown';
            $title = $this->stringValue($candidateMap[$machineName]['title'] ?? $machineName);
            $items[] = $this->escape($title) . ' — ' . $this->escape($this->compatibilityLabel($status));
        }

        return $items === [] ? '' : 'Core compatibility: ' . implode('; ', $items);
    }

    /**
     * @param array<string, mixed> $review
     */
    private function reviewSection(array $review): string
    {
        $html = '<section><h2>Module review</h2>';
        $html .= '<h3>Module purpose</h3><p>' . $this->escape($this->stringValue($review['purpose'] ?? 'Unavailable')) . '</p>';
        $html .= $this->reusabilitySection($review['reusability'] ?? null);
        $html .= $this->contribReadinessSection($review['contrib_readiness'] ?? null);
        $html .= '</section>';

        return $html;
    }

    private function reusabilitySection(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $status = is_string($value['status'] ?? null) ? $value['status'] : 'unknown';

        return '<h3>Reusability</h3>'
            . '<p><strong>Status:</strong> ' . $this->statusBadge($this->label($status), $this->statusSemanticType($status))
            . '<br><strong>Confidence:</strong> ' . $this->formatConfidence($value['confidence'] ?? null) . '</p>'
            . $this->findingsList($value['findings'] ?? []);
    }

    private function contribReadinessSection(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $status = is_string($value['status'] ?? null) ? $value['status'] : 'unknown';
        $html = '<h3>Contribution readiness</h3>'
            . '<p><strong>Status:</strong> ' . $this->statusBadge($this->label($status), $this->statusSemanticType($status)) . '</p>';
        $requiredChanges = $value['required_changes'] ?? [];
        $advisoryNotes = $value['advisory_notes'] ?? [];
        if (is_array($requiredChanges) && $requiredChanges !== []) {
            $html .= $this->findingsList($requiredChanges, 'Required changes before contribution', 'required');
        }
        if (is_array($advisoryNotes) && $advisoryNotes !== []) {
            $html .= $this->findingsList($advisoryNotes, 'Advisory notes', 'advisory');
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function discoverySection(array $analysis): string
    {
        $searches = is_array($analysis['searches'] ?? null) ? $analysis['searches'] : [];
        $candidates = is_array($analysis['candidates'] ?? null) ? $analysis['candidates'] : [];
        $uniqueCandidates = $this->discoveryCount($analysis, 'uniqueCandidatesDiscovered', count($candidates));
        $comparedCandidates = $this->discoveryCount($analysis, 'candidatesSentToComparison', count($candidates));

        return '<section><h2>Drupal.org discovery</h2><p>'
            . '<strong>Searches performed:</strong> ' . count($searches)
            . '<br><strong>Candidate projects found:</strong> ' . $uniqueCandidates
            . '<br><strong>Projects compared:</strong> ' . $comparedCandidates
            . '</p></section>';
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function discoveryCount(array $analysis, string $key, int $fallback): int
    {
        $discovery = $analysis['discovery'] ?? null;
        if (!is_array($discovery) || !is_int($discovery[$key] ?? null)) {
            return $fallback;
        }

        return $discovery[$key];
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $comparison
     */
    private function comparisonSection(array $analysis, array $comparison): string
    {
        $html = '<section><h2>Functional overlap</h2>'
            . '<p><strong>Verdict:</strong> ' . $this->escape($this->label($comparison['verdict'] ?? 'unknown'))
            . '<br><strong>Confidence:</strong> ' . $this->formatConfidence($comparison['confidence'] ?? null)
            . '</p><p>' . $this->escape($this->stringValue($comparison['summary'] ?? 'Unavailable')) . '</p>'
            . '<aside class="evidence-scope"><strong>Evidence scope</strong><br>'
            . 'Comparison uses public Drupal.org metadata; candidate source code was not downloaded. '
            . 'Missing metadata is treated as unverified.'
            . '</aside>';
        $candidateMap = $this->candidateMap($analysis['candidates'] ?? []);
        $comparisonCandidates = is_array($comparison['candidates'] ?? null) ? $comparison['candidates'] : [];
        $detailedCandidates = [];
        $relatedCandidates = [];
        foreach ($comparisonCandidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if (in_array($candidate['assessment'] ?? null, ['likely_duplicate', 'possible_overlap'], true)) {
                $detailedCandidates[] = $candidate;
            } elseif (($candidate['assessment'] ?? null) === 'related_not_duplicate') {
                $relatedCandidates[] = $candidate;
            }
        }
        usort($detailedCandidates, static function (array $left, array $right): int {
            $priority = ['likely_duplicate' => 0, 'possible_overlap' => 1];

            return ($priority[$left['assessment']] ?? 2) <=> ($priority[$right['assessment']] ?? 2);
        });
        if ($detailedCandidates !== []) {
            $html .= '<h3>Potential functional overlaps</h3>';
            foreach ($detailedCandidates as $candidate) {
                $html .= $this->comparisonCard($candidate, $candidateMap, $analysis);
            }
        }
        if ($relatedCandidates !== []) {
            $html .= '<h3>Other reviewed candidates</h3><ul>';
            foreach ($relatedCandidates as $candidate) {
                $machineName = $this->stringValue($candidate['machine_name'] ?? 'Unavailable');
                $html .= '<li>' . $this->escape($machineName) . ' — '
                    . $this->escape($this->label('related_not_duplicate')) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '</section>';
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function sourceSection(array $analysis): string
    {
        $source = is_array($analysis['source'] ?? null) ? $analysis['source'] : [];
        $skipped = is_array($source['skipped'] ?? null) ? $source['skipped'] : [];
        $redactions = is_array($source['redactions'] ?? null) ? $source['redactions'] : [];
        $coverage = is_array($source['coverage'] ?? null) ? $source['coverage'] : [];
        $mode = ($coverage['mode'] ?? 'full') === 'selected' ? 'Partial' : 'Full';
        $eligibleFiles = is_int($coverage['eligibleFiles'] ?? null)
            ? $coverage['eligibleFiles']
            : (is_int($source['files'] ?? null) ? $source['files'] : 0);
        $selectedFiles = is_int($coverage['selectedFiles'] ?? null)
            ? $coverage['selectedFiles']
            : (is_int($source['files'] ?? null) ? $source['files'] : 0);
        $html = '<section><h2>Analysis scope</h2><p>'
            . '<strong>Source coverage:</strong> ' . $mode
            . '<br><strong>Files analyzed:</strong> ' . $selectedFiles
            . ($mode === 'Partial' ? ' of ' . $eligibleFiles : '')
            . '<br><strong>Skipped files:</strong> ' . count($skipped)
            . '<br><strong>Redactions:</strong> ' . count($redactions) . '</p>';
        if ($mode === 'Partial') {
            $omittedFiles = is_int($coverage['omittedFiles'] ?? null)
                ? $coverage['omittedFiles']
                : max(0, $eligibleFiles - $selectedFiles);
            $html .= '<p><strong>Files omitted due to source budget:</strong> ' . $omittedFiles . '</p>';
        }
        if ($skipped !== []) {
            $html .= '<h3>Skipped files</h3><ul>';
            foreach ($skipped as $item) {
                if (is_array($item)) {
                    $html .= '<li>' . $this->escape($this->stringValue($item['path'] ?? 'Unavailable'))
                        . ' (' . $this->escape($this->stringValue($item['reason'] ?? 'unknown')) . ')</li>';
                }
            }
            $html .= '</ul>';
        }
        if ($redactions !== []) {
            $html .= '<h3>Redactions</h3><ul>';
            foreach ($redactions as $item) {
                if (is_array($item)) {
                    $html .= '<li>' . $this->escape($this->stringValue($item['path'] ?? 'Unavailable'))
                        . ', line ' . $this->integerValue($item['line'] ?? null)
                        . ': ' . $this->escape($this->stringValue($item['type'] ?? 'unknown')) . '</li>';
                }
            }
            $html .= '</ul>';
        }

        return $html . '</section>';
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed>|null $review
     */
    private function limitationsSection(array $analysis, ?array $review): string
    {
        $limitations = [];
        if ($review !== null && is_array($review['analysis_limits'] ?? null)) {
            foreach ($review['analysis_limits'] as $limitation) {
                if (is_string($limitation) && trim($limitation) !== '') {
                    $limitations[] = $limitation;
                }
            }
        }
        if (is_array($analysis['limitations'] ?? null)) {
            foreach ($analysis['limitations'] as $limitation) {
                if (is_array($limitation) && is_string($limitation['message'] ?? null)) {
                    $limitations[] = $limitation['message'];
                }
            }
        }

        $coverage = is_array($analysis['source']['coverage'] ?? null) ? $analysis['source']['coverage'] : [];
        $partial = ($coverage['mode'] ?? 'full') === 'selected';
        if ($partial) {
            $limitations[] = 'Only a selected subset of the source was reviewed; omitted files may contain additional evidence.';
        }
        $limitations = array_values(array_unique($limitations));
        if ($limitations === []) {
            return '';
        }

        $html = '<section><h2>Analysis limitations</h2><ul>';
        foreach ($limitations as $limitation) {
            $html .= '<li>' . $this->escape($limitation) . '</li>';
        }

        return $html . '</ul></section>';
    }

    private function definition(string $label, string $value): string
    {
        return '<dt>' . $this->escape($label) . '</dt><dd>' . $this->escape($value) . '</dd>';
    }

    private function findingsList(mixed $findings, string $heading = 'Findings', string $semanticType = 'neutral'): string
    {
        if (!is_array($findings) || $findings === []) {
            return '';
        }

        $html = '<h4>' . $this->escape($heading) . '</h4><ul class="finding-list ' . $this->escape($semanticType) . '">';
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $html .= '<li><strong>' . $this->escape($this->label($finding['severity'] ?? 'unknown')) . '</strong> '
                . $this->escape($this->stringValue($finding['file'] ?? 'Unavailable'))
                . ':' . $this->integerValue($finding['line'] ?? null) . '<br>'
                . '<strong>Evidence:</strong> ' . $this->escape($this->stringValue($finding['evidence'] ?? 'Unavailable')) . '<br>'
                . '<strong>Reason:</strong> ' . $this->escape($this->stringValue($finding['reason'] ?? 'Unavailable')) . '<br>'
                . '<strong>Suggestion:</strong> ' . $this->escape($this->stringValue($finding['suggestion'] ?? 'Unavailable'))
                . '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function candidateMap(mixed $candidates): array
    {
        if (!is_array($candidates)) {
            return [];
        }

        $map = [];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && is_string($candidate['machineName'] ?? null)) {
                $map[$candidate['machineName']] = $candidate;
            }
        }

        return $map;
    }

    private function formatConfidence(mixed $value): string
    {
        return is_int($value) || is_float($value)
            ? $this->escape((string) $value)
            : 'Unavailable';
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, array<string, mixed>> $candidateMap
     * @param array<string, mixed> $analysis
     */
    private function comparisonCard(array $candidate, array $candidateMap, array $analysis): string
    {
        $machineName = $this->stringValue($candidate['machine_name'] ?? 'Unavailable');
        $poolCandidate = $candidateMap[$machineName] ?? [];
        $title = $this->stringValue($poolCandidate['title'] ?? 'Unavailable');
        $url = $this->stringValue($poolCandidate['url'] ?? 'Unavailable');

        $assessment = is_string($candidate['assessment'] ?? null) ? $candidate['assessment'] : 'unknown';

        $html = '<article class="comparison-card"><h4>' . $this->escape($title) . ' '
            . $this->statusBadge($this->label($assessment), $this->statusSemanticType($assessment)) . '</h4>'
            . '<p><strong>Project:</strong> ' . $this->escape($machineName)
            . '<br><span class="url">' . $this->escape($url) . '</span></p>';
        if (is_array($candidate['overlap'] ?? null) && $candidate['overlap'] !== []) {
            $html .= '<h4>Overlap</h4>' . $this->listBlock($candidate['overlap']);
        }
        if (is_array($candidate['confirmed_differences'] ?? null) && $candidate['confirmed_differences'] !== []) {
            $html .= '<h4>Confirmed differences</h4>' . $this->listBlock($candidate['confirmed_differences']);
        }
        if (is_array($candidate['unverified_details'] ?? null) && $candidate['unverified_details'] !== []) {
            $html .= '<h4>Unverified details</h4>' . $this->listBlock($candidate['unverified_details'], 'Unavailable', 'unverified');
        }
        $reason = $this->stringValue($candidate['reason'] ?? '');
        if ($reason !== '') {
            $html .= '<p><strong>Reason:</strong> ' . $this->escape($reason) . '</p>';
        }

        return $html . $this->compatibilityBlock($candidate, $analysis) . '</article>';
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $analysis
     */
    private function compatibilityBlock(array $candidate, array $analysis): string
    {
        $machineName = $this->stringValue($candidate['machine_name'] ?? '');
        $compatibilities = is_array($analysis['candidateCompatibility'] ?? null)
            ? $analysis['candidateCompatibility']
            : [];
        $compatibility = is_array($compatibilities[$machineName] ?? null)
            ? $compatibilities[$machineName]
            : [];
        $status = is_string($compatibility['status'] ?? null) ? $compatibility['status'] : 'unknown';
        $targetMajor = is_int($compatibility['targetCoreMajor'] ?? null)
            ? $compatibility['targetCoreMajor']
            : null;
        $targetLabel = $targetMajor === null ? 'target Drupal core' : 'Drupal ' . $targetMajor;
        $label = match ($status) {
            'compatible' => 'Compatible with ' . $targetLabel,
            'outdated' => 'Outdated for ' . $targetLabel,
            'requires_newer_core' => 'Requires newer core than ' . $targetLabel,
            default => 'Not verified',
        };
        $html = '<h4>Core compatibility</h4><p>'
            . $this->statusBadge($label, $this->compatibilitySemanticType($status)) . '</p>';
        if ($status === 'outdated') {
            $supported = is_array($compatibility['supportedCoreMajors'] ?? null)
                ? $compatibility['supportedCoreMajors']
                : [];
            $supported = array_values(array_filter($supported, 'is_int'));
            if ($supported !== []) {
                $html .= '<p><strong>Latest verified core support:</strong> Drupal '
                    . max($supported) . '</p>';
            }
            $html .= '<p>Consider contributing core compatibility before creating a new project.</p>';
        }

        return $html;
    }

    private function listBlock(mixed $items, string $emptyMessage = 'Unavailable', string $semanticType = 'neutral'): string
    {
        if (!is_array($items) || $items === []) {
            return '<p class="' . $semanticType . '">' . $this->escape($emptyMessage) . '</p>';
        }

        $html = '<ul class="' . $this->escape($semanticType) . '">';
        foreach ($items as $item) {
            if (is_string($item)) {
                $html .= '<li>' . $this->escape($item) . '</li>';
            }
        }

        return $html . '</ul>';
    }

    private function statusBadge(string $label, string $semanticType): string
    {
        return '<span class="status-badge ' . $this->escape($semanticType) . '">' . $this->escape($label) . '</span>';
    }

    private function statusSemanticType(string $status): string
    {
        return match ($status) {
            'likely_duplicate', 'not_candidate_yet', 'significant_changes', 'client_specific' => 'danger',
            'possible_overlap', 'needs_generalization', 'needs_changes', 'candidate_with_changes', 'outdated', 'advisory' => 'warning',
            'reusable', 'ready', 'candidate', 'compatible' => 'success',
            default => 'neutral',
        };
    }

    private function compatibilitySemanticType(string $status): string
    {
        return match ($status) {
            'compatible' => 'success',
            'outdated', 'requires_newer_core' => 'warning',
            default => 'neutral',
        };
    }

    private function integerValue(mixed $value): string
    {
        return is_int($value) ? (string) $value : '0';
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : 'Unavailable';
    }

    private function label(mixed $value): string
    {
        if (!is_string($value)) {
            return 'Unknown';
        }

        $labels = [
            'needs_generalization' => 'Needs Generalization',
            'needs_changes' => 'Needs Changes',
            'significant_changes' => 'Significant Changes',
            'likely_duplicate' => 'Likely Duplicate',
            'possible_overlap' => 'Possible Overlap',
            'related_not_duplicate' => 'Related, Not Duplicate',
            'not_candidate_yet' => 'Not Candidate Yet',
            'candidate_with_changes' => 'Candidate With Changes',
            'candidate' => 'Candidate for Contribution',
            'unclear' => 'Unclear',
        ];

        if (isset($labels[$value])) {
            return $labels[$value];
        }

        return ucwords(str_replace('_', ' ', $value));
    }

    private function compatibilityLabel(string $status): string
    {
        return match ($status) {
            'compatible' => 'Compatible',
            'outdated' => 'Outdated',
            'requires_newer_core' => 'Requires newer core',
            default => 'Not verified',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function writeAtomically(string $targetPath, string $pdf): void
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory)) {
            throw new \RuntimeException('Could not write PDF review report.');
        }

        $temporaryPath = tempnam($directory, '.drupalorg-review-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Could not write PDF review report.');
        }

        try {
            @chmod($temporaryPath, 0600);
            $written = file_put_contents($temporaryPath, $pdf, LOCK_EX);
            if ($written !== strlen($pdf)) {
                throw new \RuntimeException('Could not write PDF review report.');
            }

            if (DIRECTORY_SEPARATOR === '\\' && is_file($targetPath) && !@unlink($targetPath)) {
                throw new \RuntimeException('Could not replace PDF review report.');
            }
            if (!@rename($temporaryPath, $targetPath)) {
                throw new \RuntimeException('Could not replace PDF review report.');
            }
            @chmod($targetPath, 0600);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function styles(): string
    {
        return 'body{font-family:DejaVu Sans,sans-serif;font-size:11pt;color:#222;line-height:1.45}'
            . 'h1{font-size:22pt;color:#174a7e;margin-bottom:5px}'
            . 'h2{font-size:17pt;color:#174a7e;border-bottom:1px solid #b8c7d9;padding-bottom:4px;margin-top:20px;margin-bottom:10px}'
            . 'h3{font-size:13pt;color:#333;margin-bottom:6px}'
            . 'h4{font-size:11pt;margin-bottom:4px}'
            . 'h2,h3{page-break-after:avoid}'
            . 'p,li{font-size:11pt;line-height:1.45}'
            . 'section{margin-bottom:18px}'
            . '.subtitle{font-size:14pt;margin-top:0}'
            . '.notice{background:#eef4fa;padding:8px;border-left:3px solid #174a7e}'
            . '.small,.meta{font-size:9.5pt;line-height:1.3;color:#555}'
            . '.comparison-card{font-size:10.5pt;line-height:1.45;border:1px solid #ccd3da;background:#f8fafc;padding:10px;margin:10px 0;page-break-inside:avoid}'
            . '.comparison-card p,.comparison-card li{font-size:10.5pt;line-height:1.45}'
            . '.status-badge{display:inline-block;padding:2px 6px;border:1px solid;border-radius:3px;font-size:9.5pt;font-weight:bold;line-height:1.2}'
            . '.status-badge.danger{background:#fdecec;border-color:#c0392b;color:#7b1e16}'
            . '.status-badge.warning{background:#fff5db;border-color:#c78a12;color:#6b4b00}'
            . '.status-badge.success{background:#e8f5e9;border-color:#388e3c;color:#1b5e20}'
            . '.status-badge.neutral{background:#f0f2f4;border-color:#8a969f;color:#3f4a50}'
            . '.evidence-scope{background:#f0f2f4;border-left:3px solid #8a969f;padding:8px;margin:10px 0;font-size:10.5pt}'
            . '.finding-list.advisory,.advisory{background:#fffaf0}'
            . '.finding-list.required,.required{background:#fff4f3}'
            . '.unverified{color:#59636b}'
            . '.url{overflow-wrap:anywhere;word-break:break-word}'
            . 'dl{display:grid;grid-template-columns:35% 65%;margin:0}'
            . 'dt,dd{padding:3px;border-bottom:1px solid #eee;margin:0}'
            . 'dt{font-weight:bold;background:#f7f7f7}'
            . 'table{width:100%;border-collapse:collapse;margin:6px 0 12px;font-size:9.5pt;line-height:1.3}'
            . 'th,td{border:1px solid #ccd3da;padding:5px;vertical-align:top}'
            . 'th{background:#eef4fa;text-align:left}'
            . 'page-break-inside:avoid{page-break-inside:avoid}'
            . 'ul,ol{margin-top:6px}'
            . 'li{margin-bottom:4px}';
    }
}
