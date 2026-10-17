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

        $html .= $this->discoverySection($analysis, $moduleInfo);

        $comparison = is_array($analysis['comparison'] ?? null) ? $analysis['comparison'] : null;
        if ($comparison !== null) {
            $html .= $this->comparisonSection($analysis, $comparison);
        }

        $assessment = is_array($analysis['assessment'] ?? null) ? $analysis['assessment'] : null;
        if ($assessment !== null) {
            $html .= $this->assessmentSignalsSection($assessment);
        }

        $html .= $this->sourceSection($analysis);
        $html .= $this->deterministicChecksSection();
        $html .= $this->limitationsSection($analysis, $review);
        $html .= $this->aiSection($analysis);
        $html .= $this->methodologySection();
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
        $html .= $this->textListSection('Capabilities', $review['capabilities'] ?? []);
        $html .= $this->textListSection('Drupal concepts', $review['drupal_concepts'] ?? []);
        $html .= $this->textListSection('Integrations', $review['integrations'] ?? []);
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
     * @param array{machineName: string, name: string, type: string, coreVersionRequirement: string} $moduleInfo
     */
    private function discoverySection(array $analysis, array $moduleInfo): string
    {
        $review = is_array($analysis['review'] ?? null) ? $analysis['review'] : [];
        $html = '<section><h2>Drupal.org discovery</h2><h3>Search strategy</h3>'
            . '<h4>Module-name search</h4>'
            . '<p>The human-readable module name is searched as an additional discovery signal. '
            . 'A matching project name does not imply that two modules are duplicates. It only helps ensure '
            . 'potentially relevant projects are available to semantic comparison.</p>'
            . '<p><strong>Human-readable name:</strong> ' . $this->escape($moduleInfo['name']) . '</p>'
            . '<h4>Core capability terms</h4>'
            . '<p>These terms describe the reusable problem or capability implemented by the module, independently of the current project or business domain.</p>'
            . "<p>They receive higher weight during candidate discovery because they are expected to represent the module's core reusable purpose.</p>"
            . $this->termList('', $review['primary_search_terms'] ?? [], true)
            . '<h4>Functional search queries</h4>'
            . '<p>These short multi-word queries describe the reusable interaction or problem more specifically than individual keywords.</p>'
            . '<p>They improve recall for relevant projects whose names may not contain the same single-word terms as the local module.</p>'
            . $this->queryList($review['functional_search_queries'] ?? [])
            . '<h4>Domain-specific terms</h4>'
            . '<p>These terms describe the domain in which the module currently appears to be used.</p>'
            . '<p>They are searched with lower ranking weight because this domain may be specific to the current project rather than part of the reusable module purpose.</p>'
            . '<p>A domain-specific term is not considered less relevant functionality. It is simply treated as weaker evidence when discovering reusable Drupal.org projects.</p>'
            . $this->termList('', $review['secondary_search_terms'] ?? [], true)
            . '<p>Drupal.org is searched independently for every term.<br>'
            . 'Module-name, core capability, and functional-query searches have higher retrieval weight.<br>'
            . 'Domain-specific searches have lower retrieval weight.<br>'
            . 'The results from all searches are then deduplicated and combined into a single candidate list.<br>'
            . 'Search matches and retrieval ranking are discovery signals only. Neither a module-name match, '
            . 'a functional-query match, nor an RRF position determines duplicate status.</p>';
        $searches = is_array($analysis['searches'] ?? null) ? $analysis['searches'] : [];
        $candidates = is_array($analysis['candidates'] ?? null) ? $analysis['candidates'] : [];
        $html .= '<p><strong>Searches:</strong> ' . count($searches)
            . '<br><strong>' . count($candidates) . ' unique candidates discovered.</strong> '
            . 'Top ' . min(10, count($candidates)) . ' shown below. '
            . 'All discovered candidates were available to the semantic comparison stage.</p>';

        if ($candidates !== []) {
            $html .= '<h3>Candidate discovery ranking</h3><p class="small">'
                . 'Results from the individual Drupal.org searches are combined using Weighted Reciprocal Rank Fusion (RRF). '
                . 'Projects that appear prominently across one or more searches are ranked higher. '
                . 'Matches from core capability searches contribute more weight than matches from domain-specific searches.</p>'
                . '<p class="small">This ranking is used only to organize candidates before semantic comparison. '
                . 'It is not a duplicate probability, a contribution-readiness score, a quality score, or a percentage of similarity. '
                . 'The final functional-overlap assessment is performed separately during the semantic comparison stage.</p>'
                . '<table><thead><tr><th>Rank</th><th>Project</th><th>Title</th><th>Matched queries</th></tr></thead><tbody>';
            foreach (array_slice($candidates, 0, 10) as $index => $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $html .= '<tr><td>' . ($index + 1) . '</td>'
                    . '<td>' . $this->escape($this->stringValue($candidate['machineName'] ?? 'Unavailable')) . '</td>'
                    . '<td>' . $this->escape($this->stringValue($candidate['title'] ?? 'Unavailable')) . '</td>'
                    . '<td>' . $this->matchedQueries($candidate, $searches) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        return $html . '</section>';
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
            . 'Candidate comparison is based on Drupal.org project metadata supplied to the semantic comparison stage. '
            . 'Remote candidate source code is not downloaded or compared.<br><br>'
            . 'A missing capability in the public description is therefore treated as unverified, not as a confirmed difference.'
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
     * @param array<string, mixed> $assessment
     */
    private function assessmentSignalsSection(array $assessment): string
    {
        $signals = is_array($assessment['signals'] ?? null) ? $assessment['signals'] : [];
        if ($signals === []) {
            return '';
        }

        $html = '<section><h2>Assessment signals</h2><p>The following validated review signals were combined deterministically to produce the advisory contribution assessment.</p><ul>';
        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $html .= '<li>' . $this->escape($this->label($signal['code'] ?? 'unknown'))
                . ' (' . $this->escape($this->signalSeverityLabel($signal['severity'] ?? 'unknown')) . ')</li>';
        }

        return $html . '</ul></section>';
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function sourceSection(array $analysis): string
    {
        $source = is_array($analysis['source'] ?? null) ? $analysis['source'] : [];
        $skipped = is_array($source['skipped'] ?? null) ? $source['skipped'] : [];
        $redactions = is_array($source['redactions'] ?? null) ? $source['redactions'] : [];
        $html = '<section><h2>Source coverage</h2><p>'
            . '<strong>Analyzed files:</strong> ' . $this->integerValue($source['files'] ?? null)
            . '<br><strong>Sanitized bytes sent to AI #1:</strong> ' . $this->integerValue($source['bytes'] ?? null)
            . '<br><strong>Skipped files:</strong> ' . count($skipped)
            . '<br><strong>Redactions:</strong> ' . count($redactions) . '</p>';
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

    private function deterministicChecksSection(): string
    {
        return '<section><h2>Deterministic code-quality checks</h2><ul>'
            . '<li>Drupal coding standards: Not verified</li>'
            . '<li>Static analysis: Not verified</li>'
            . '<li>Automated test suite: Not verified</li>'
            . '<li>Automated security analysis: Not verified</li>'
            . '</ul></section>';
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

        $html = '<section><h2>Analysis limitations</h2>'
            . '<p>The review is based only on the selected source that was collected. '
            . 'Skipped or unavailable files may limit the conclusions.</p>';
        if ($limitations !== []) {
            $html .= '<ul>';
            foreach (array_unique($limitations) as $limitation) {
                $html .= '<li>' . $this->escape($limitation) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '</section>';
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function aiSection(array $analysis): string
    {
        $ai = is_array($analysis['ai'] ?? null) ? $analysis['ai'] : [];
        $html = '<section><h2>AI execution information</h2><table><thead><tr>'
            . '<th>Phase</th><th>Provider</th><th>Model</th><th>Input tokens</th><th>Output tokens</th><th>Total tokens</th>'
            . '</tr></thead><tbody>';
        foreach (['review' => 'Module analysis', 'comparison' => 'Candidate comparison'] as $key => $label) {
            $metadata = is_array($ai[$key] ?? null) ? $ai[$key] : [];
            $usage = is_array($metadata['tokenUsage'] ?? null) ? $metadata['tokenUsage'] : [];
            $html .= '<tr><td>' . $label . '</td>'
                . '<td>' . $this->escape($this->stringValue($metadata['providerId'] ?? 'Unavailable')) . '</td>'
                . '<td>' . $this->escape($this->stringValue($metadata['modelId'] ?? 'Unavailable')) . '</td>'
                . '<td>' . $this->formatToken($usage['input'] ?? null) . '</td>'
                . '<td>' . $this->formatToken($usage['output'] ?? null) . '</td>'
                . '<td>' . $this->formatToken($usage['total'] ?? null) . '</td></tr>';
        }

        return $html . '</tbody></table></section>';
    }

    private function methodologySection(): string
    {
        return '<section><h2>Methodology</h2><ol>'
            . '<li>Local source collection.</li>'
            . '<li>Local best-effort sensitive-data sanitization.</li>'
            . '<li>AI-assisted module characterization and retrieval-query generation.</li>'
            . '<li>Direct Drupal.org searches using module-name, core-capability, functional-query, and domain-specific signals.</li>'
            . '<li>Weighted Reciprocal Rank Fusion for candidate discovery ordering.</li>'
            . '<li>AI-assisted semantic comparison of discovered candidates.</li>'
            . '<li>Deterministic mapping of validated review signals into the advisory contribution assessment.</li>'
            . '<li>Deterministic Drupal core compatibility enrichment for relevant candidates.</li>'
            . '<li>Local PDF rendering.</li>'
            . '</ol><p>AI does not invent the Drupal.org candidate catalog. '
            . 'RRF does not determine duplicate status. Core compatibility is evaluated separately after the functional contribution '
            . 'assessment and does not change the semantic duplicate verdict. '
            . 'The final recommendation is advisory.</p></section>';
    }

    private function definition(string $label, string $value): string
    {
        return '<dt>' . $this->escape($label) . '</dt><dd>' . $this->escape($value) . '</dd>';
    }

    private function textListSection(string $title, mixed $items): string
    {
        if (!is_array($items) || $items === []) {
            return '';
        }

        $html = '<h3>' . $this->escape($title) . '</h3><ul>';
        foreach ($items as $item) {
            if (is_string($item)) {
                $html .= '<li>' . $this->escape($item) . '</li>';
            }
        }

        return $html . '</ul>';
    }

    private function termList(string $title, mixed $items, bool $includeReasons = false): string
    {
        if (!is_array($items) || $items === []) {
            return '';
        }

        $html = $title === '' ? '<ul>' : '<h3>' . $this->escape($title) . '</h3><ul>';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $term = $this->stringValue($item['term'] ?? 'Unavailable');
            $reason = $this->stringValue($item['reason'] ?? 'Unavailable');
            $html .= '<li><strong>' . $this->escape($term) . '</strong>'
                . ($includeReasons ? '<br>' . $this->escape($reason) : '') . '</li>';
        }

        return $html . '</ul>';
    }

    private function queryList(mixed $items): string
    {
        if (!is_array($items) || $items === []) {
            return '';
        }

        $html = '<ul>';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $query = $this->stringValue($item['query'] ?? 'Unavailable');
            $reason = $this->stringValue($item['reason'] ?? 'Unavailable');
            $html .= '<li><strong>' . $this->escape($query) . '</strong><br>'
                . $this->escape($reason) . '</li>';
        }

        return $html . '</ul>';
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
     */
    private function matchedQueries(array $candidate, mixed $searches): string
    {
        $typeByTerm = [];
        if (is_array($searches)) {
            foreach ($searches as $search) {
                if (!is_array($search) || !is_string($search['term'] ?? null)) {
                    continue;
                }
                $term = preg_replace('/\s+/u', ' ', trim($search['term'])) ?? trim($search['term']);
                $type = is_string($search['type'] ?? null) ? $search['type'] : 'core';
                $typeByTerm[strtolower($term)] = $type;
            }
        }
        $matches = [];
        foreach (['primaryMatches' => 'core', 'secondaryMatches' => 'domain'] as $key => $fallback) {
            $values = $candidate[$key] ?? [];
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (is_array($value) && is_string($value['term'] ?? null)) {
                    $term = $value['term'];
                    $type = $typeByTerm[strtolower($term)] ?? $fallback;
                    $label = $type === 'name' ? 'module name' : $type;
                    $matches[] = $this->escape($term) . ' (' . $label . ')';
                }
            }
        }

        return $matches === [] ? 'Unavailable' : implode('<br>', $matches);
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

        return '<article class="comparison-card"><h4>' . $this->escape($title) . ' '
            . $this->statusBadge($this->label($assessment), $this->statusSemanticType($assessment)) . '</h4>'
            . '<p><strong>Project:</strong> ' . $this->escape($machineName) . '<br>'
            . '<strong>Drupal.org:</strong><br><span class="url">' . $this->escape($url) . '</span><br>'
            . '<strong>Assessment:</strong> ' . $this->statusBadge($this->label($assessment), $this->statusSemanticType($assessment)) . '</p>'
            . '<h4>Overlap</h4>' . $this->listBlock($candidate['overlap'] ?? [])
            . '<h4>Confirmed differences</h4>' . $this->listBlock(
                $candidate['confirmed_differences'] ?? [],
                'None established from the supplied candidate metadata.',
            )
            . '<h4>Unverified details</h4>' . $this->listBlock(
                $candidate['unverified_details'] ?? [],
                'None.',
                'unverified',
            )
            . '<h4>Reason</h4><p>' . $this->escape($this->stringValue($candidate['reason'] ?? 'Unavailable')) . '</p>'
            . $this->compatibilityBlock($candidate, $analysis)
            . '</article>';
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
            $html .= '<h4>Contribution consideration</h4><p>This project appears to solve substantially the same reusable problem, '
                . 'but no compatible release for the target Drupal core version was verified. Before creating a new Drupal.org project, '
                . 'consider whether contributing target core compatibility to the existing project is viable.</p>';
        } elseif ($status === 'compatible') {
            $html .= '<p>A functionally similar project with verified target-core compatibility exists.</p>';
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

    private function signalSeverityLabel(mixed $value): string
    {
        if ($value === 'blocking') {
            return 'Strong negative signal';
        }
        if ($value === 'warning') {
            return 'Advisory signal';
        }

        return $this->label($value);
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

    private function formatToken(mixed $value): string
    {
        return is_int($value) ? $this->escape((string) $value) : 'Unavailable';
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
