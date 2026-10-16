<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ModuleContributionAssessmentResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleContributionAssessmentResolver::class)]
class ModuleContributionAssessmentResolverTest extends TestCase
{
    public function testResolvesCandidate(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('reusable', 'ready'),
            self::comparison('no_clear_duplicate'),
        );

        self::assertSame([
            'status' => 'candidate',
            'signals' => [],
        ], $result);
    }

    public function testResolvesPossibleOverlapAsCandidateWithChanges(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('reusable', 'ready'),
            self::comparison('possible_overlap'),
        );

        self::assertSame('candidate_with_changes', $result['status']);
        self::assertSame(
            [['code' => 'possible_overlap', 'severity' => 'warning']],
            $result['signals'],
        );
    }

    public function testResolvesGeneralizationAsCandidateWithChanges(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('needs_generalization', 'ready'),
            self::comparison('no_clear_duplicate'),
        );

        self::assertSame('candidate_with_changes', $result['status']);
        self::assertSame(
            [['code' => 'needs_generalization', 'severity' => 'warning']],
            $result['signals'],
        );
    }

    public function testResolvesNeedsChangesAsCandidateWithChanges(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('reusable', 'needs_changes'),
            self::comparison('no_clear_duplicate'),
        );

        self::assertSame('candidate_with_changes', $result['status']);
        self::assertSame(
            [['code' => 'needs_changes', 'severity' => 'warning']],
            $result['signals'],
        );
    }

    public function testResolvesLikelyDuplicateAsNotCandidateYet(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('reusable', 'ready'),
            self::comparison('likely_duplicate'),
        );

        self::assertSame('not_candidate_yet', $result['status']);
        self::assertSame(
            [['code' => 'likely_duplicate', 'severity' => 'blocking']],
            $result['signals'],
        );
    }

    public function testResolvesClientSpecificAsNotCandidateYet(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('client_specific', 'ready'),
            self::comparison('no_clear_duplicate'),
        );

        self::assertSame('not_candidate_yet', $result['status']);
        self::assertSame(
            [['code' => 'client_specific', 'severity' => 'blocking']],
            $result['signals'],
        );
    }

    public function testResolvesSignificantChangesAsNotCandidateYet(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('reusable', 'significant_changes'),
            self::comparison('no_clear_duplicate'),
        );

        self::assertSame('not_candidate_yet', $result['status']);
        self::assertSame(
            [['code' => 'significant_changes', 'severity' => 'blocking']],
            $result['signals'],
        );
    }

    public function testUnclearReusabilityProducesWarning(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('unclear', 'ready'),
            self::comparison('no_clear_duplicate'),
        );

        self::assertSame('candidate_with_changes', $result['status']);
        self::assertSame(
            [['code' => 'reusability_unclear', 'severity' => 'warning']],
            $result['signals'],
        );
    }

    public function testUnclearContribReadinessProducesWarning(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('reusable', 'unclear'),
            self::comparison('no_clear_duplicate'),
        );

        self::assertSame('candidate_with_changes', $result['status']);
        self::assertSame(
            [['code' => 'contrib_readiness_unclear', 'severity' => 'warning']],
            $result['signals'],
        );
    }

    public function testPreservesWarningOrder(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('needs_generalization', 'needs_changes'),
            self::comparison('possible_overlap'),
        );

        self::assertSame('candidate_with_changes', $result['status']);
        self::assertSame(
            [
                ['code' => 'possible_overlap', 'severity' => 'warning'],
                ['code' => 'needs_generalization', 'severity' => 'warning'],
                ['code' => 'needs_changes', 'severity' => 'warning'],
            ],
            $result['signals'],
        );
    }

    public function testPreservesBlockerOrder(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('client_specific', 'significant_changes'),
            self::comparison('likely_duplicate'),
        );

        self::assertSame('not_candidate_yet', $result['status']);
        self::assertSame(
            [
                ['code' => 'likely_duplicate', 'severity' => 'blocking'],
                ['code' => 'client_specific', 'severity' => 'blocking'],
                ['code' => 'significant_changes', 'severity' => 'blocking'],
            ],
            $result['signals'],
        );
    }

    public function testKeepsWarningsWhenBlockerExists(): void
    {
        $result = $this->resolver()->resolve(
            self::moduleReview('client_specific', 'needs_changes'),
            self::comparison('possible_overlap'),
        );

        self::assertSame('not_candidate_yet', $result['status']);
        self::assertSame(
            [
                ['code' => 'possible_overlap', 'severity' => 'warning'],
                ['code' => 'client_specific', 'severity' => 'blocking'],
                ['code' => 'needs_changes', 'severity' => 'warning'],
            ],
            $result['signals'],
        );
    }

    public function testIgnoresConfidence(): void
    {
        $lowConfidenceReview = self::moduleReview('reusable', 'ready');
        $highConfidenceReview = $lowConfidenceReview;
        $lowConfidenceReview['reusability']['confidence'] = 0.01;
        $highConfidenceReview['reusability']['confidence'] = 0.99;

        $lowConfidenceComparison = self::comparison('possible_overlap');
        $highConfidenceComparison = $lowConfidenceComparison;
        $lowConfidenceComparison['confidence'] = 0.01;
        $highConfidenceComparison['confidence'] = 0.99;

        self::assertSame(
            $this->resolver()->resolve($lowConfidenceReview, $lowConfidenceComparison),
            $this->resolver()->resolve($highConfidenceReview, $highConfidenceComparison),
        );
    }

    public function testIgnoresFreeTextAndCandidateData(): void
    {
        $plainReview = self::moduleReview('reusable', 'ready');
        $richReview = $plainReview;
        $richReview['reusability']['findings'] = ['unexpected' => 'text'];
        $richReview['contrib_readiness']['summary'] = 'arbitrary summary';
        $richReview['candidates'] = [['verdict' => 'likely_duplicate']];

        $plainComparison = self::comparison('no_clear_duplicate');
        $richComparison = $plainComparison;
        $richComparison['summary'] = 'arbitrary overlap';
        $richComparison['overlap'] = ['differences' => 'ignored'];

        self::assertSame(
            $this->resolver()->resolve($plainReview, $plainComparison),
            $this->resolver()->resolve($richReview, $richComparison),
        );
    }

    public function testDoesNotMutateInputs(): void
    {
        $moduleReview = self::moduleReview('reusable', 'ready');
        $comparison = self::comparison('no_clear_duplicate');
        $originalModuleReview = $moduleReview;
        $originalComparison = $comparison;

        $this->resolver()->resolve($moduleReview, $comparison);

        self::assertSame($originalModuleReview, $moduleReview);
        self::assertSame($originalComparison, $comparison);
    }

    #[DataProvider('invalidInputProvider')]
    public function testRejectsInvalidInput(string $case): void
    {
        $moduleReview = self::moduleReview('reusable', 'ready');
        $comparison = self::comparison('no_clear_duplicate');

        switch ($case) {
            case 'missing reusability':
                unset($moduleReview['reusability']);
                break;
            case 'missing reusability status':
                unset($moduleReview['reusability']['status']);
                break;
            case 'invalid reusability status':
                $moduleReview['reusability']['status'] = 'invalid';
                break;
            case 'missing contrib readiness':
                unset($moduleReview['contrib_readiness']);
                break;
            case 'invalid contrib readiness status':
                $moduleReview['contrib_readiness']['status'] = 'invalid';
                break;
            case 'missing verdict':
                unset($comparison['verdict']);
                break;
            case 'invalid verdict':
                $comparison['verdict'] = 'invalid';
                break;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Module contribution assessment input is invalid.');
        $this->resolver()->resolve($moduleReview, $comparison);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'missing reusability' => ['missing reusability'];
        yield 'missing reusability status' => ['missing reusability status'];
        yield 'invalid reusability status' => ['invalid reusability status'];
        yield 'missing contrib readiness' => ['missing contrib readiness'];
        yield 'invalid contrib readiness status' => ['invalid contrib readiness status'];
        yield 'missing verdict' => ['missing verdict'];
        yield 'invalid verdict' => ['invalid verdict'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function moduleReview(string $reusability, string $contribReadiness): array
    {
        return [
            'reusability' => [
                'status' => $reusability,
                'confidence' => 0.5,
            ],
            'contrib_readiness' => [
                'status' => $contribReadiness,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function comparison(string $verdict): array
    {
        return [
            'verdict' => $verdict,
        ];
    }

    private function resolver(): ModuleContributionAssessmentResolver
    {
        return new ModuleContributionAssessmentResolver();
    }
}
