<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ModuleReviewComparisonResultValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleReviewComparisonResultValidator::class)]
class ModuleReviewComparisonResultValidatorTest extends TestCase
{
    public function testValidatesPossibleOverlapAndTrimsText(): void
    {
        $result = self::comparisonResult([
            self::outputCandidate('record', 'possible_overlap', [' records '], [' ownership '], ' overlaps '),
            self::outputCandidate('profile', 'related_not_duplicate', [], [], ' related '),
        ]);
        $result['summary'] = ' summary ';

        $validated = $this->validator()->validate($result, self::pool());

        self::assertSame('possible_overlap', $validated['verdict']);
        self::assertSame(0.95, $validated['confidence']);
        self::assertSame('summary', $validated['summary']);
        self::assertSame(['records'], $validated['candidates'][0]['overlap']);
        self::assertSame(['ownership'], $validated['candidates'][0]['confirmed_differences']);
        self::assertSame('overlaps', $validated['candidates'][0]['reason']);
        self::assertSame(['record', 'profile'], array_column($validated['candidates'], 'machine_name'));
    }

    public function testValidatesNoClearDuplicateWithNoCandidates(): void
    {
        $result = self::comparisonResult([], 'no_clear_duplicate');

        self::assertSame($result, $this->validator()->validate($result, self::pool()));
    }

    public function testValidatesOnlyRelatedCandidates(): void
    {
        $result = self::comparisonResult([
            self::outputCandidate('profile', 'related_not_duplicate', [], [], 'Related resource.'),
        ], 'no_clear_duplicate');

        self::assertSame('no_clear_duplicate', $this->validator()->validate($result, self::pool())['verdict']);
    }

    public function testLikelyDuplicateWinsTheGlobalVerdict(): void
    {
        $result = self::comparisonResult([
            self::outputCandidate('profile', 'likely_duplicate', ['profiles']),
            self::outputCandidate('record', 'possible_overlap', ['records']),
            self::outputCandidate('professional_directory', 'related_not_duplicate', []),
        ], 'likely_duplicate');

        self::assertSame('likely_duplicate', $this->validator()->validate($result, self::pool())['verdict']);
    }

    public function testAcceptsLikelyDuplicateWithUnverifiedDetailsAndNoConfirmedDifferences(): void
    {
        $result = self::comparisonResult([
            self::outputCandidate('profile', 'likely_duplicate', ['profiles'], [], 'Same reusable purpose.'),
        ], 'likely_duplicate');
        $result['candidates'][0]['unverified_details'] = [
            'The public metadata does not establish whether access filtering is available.',
        ];

        $validated = $this->validator()->validate($result, self::pool());

        self::assertSame([], $validated['candidates'][0]['confirmed_differences']);
        self::assertSame(
            ['The public metadata does not establish whether access filtering is available.'],
            $validated['candidates'][0]['unverified_details'],
        );
        self::assertSame('likely_duplicate', $validated['verdict']);
    }

    public function testAcceptsConfirmedDifferencesWithoutUnverifiedDetails(): void
    {
        $result = self::comparisonResult([
            self::outputCandidate('profile', 'possible_overlap', ['profiles'], ['Different scope.']),
        ]);

        self::assertSame([], $this->validator()->validate($result, self::pool())['candidates'][0]['unverified_details']);
    }

    public function testPossibleOverlapWinsWithoutLikelyDuplicate(): void
    {
        $result = self::comparisonResult([
            self::outputCandidate('profile', 'related_not_duplicate', []),
            self::outputCandidate('record', 'possible_overlap', ['records']),
        ]);

        self::assertSame('possible_overlap', $this->validator()->validate($result, self::pool())['verdict']);
    }

    #[DataProvider('inconsistentVerdictProvider')]
    public function testRejectsInconsistentVerdicts(array $result): void
    {
        $this->expectInvalidResult();
        $this->validator()->validate($result, self::pool());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function inconsistentVerdictProvider(): iterable
    {
        yield 'possible candidate with no clear verdict' => [self::comparisonResult([
            self::outputCandidate('profile', 'possible_overlap', ['profiles']),
        ], 'no_clear_duplicate')];
        yield 'likely candidate with possible verdict' => [self::comparisonResult([
            self::outputCandidate('profile', 'likely_duplicate', ['profiles']),
        ], 'possible_overlap')];
        yield 'empty candidates with likely verdict' => [self::comparisonResult([], 'likely_duplicate')];
    }

    #[DataProvider('invalidMachineNameProvider')]
    public function testRejectsInvalidOutputMachineNames(string $machineName): void
    {
        $result = self::comparisonResult([
            self::outputCandidate($machineName, 'possible_overlap', ['profiles']),
        ]);

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::pool());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMachineNameProvider(): iterable
    {
        yield 'invented' => ['imaginary_project'];
        yield 'different case' => ['Profile'];
        yield 'external whitespace' => [' profile '];
    }

    public function testRejectsDuplicateOutputCandidates(): void
    {
        $result = self::comparisonResult([
            self::outputCandidate('profile', 'possible_overlap', ['profiles']),
            self::outputCandidate('profile', 'possible_overlap', ['profiles']),
        ]);

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::pool());
    }

    public function testAcceptsEightCandidatesAndRejectsNine(): void
    {
        $pool = [];
        $output = [];
        for ($index = 1; $index <= 8; $index++) {
            $machineName = 'candidate_' . $index;
            $pool[] = self::poolCandidate($machineName, (float) $index);
            $output[] = self::outputCandidate($machineName, 'related_not_duplicate', []);
        }

        $result = self::comparisonResult($output, 'no_clear_duplicate');
        self::assertCount(8, $this->validator()->validate($result, $pool)['candidates']);

        $result['candidates'][] = self::outputCandidate('candidate_9', 'related_not_duplicate', []);
        $this->expectInvalidResult();
        $this->validator()->validate($result, $pool);
    }

    #[DataProvider('validConfidenceProvider')]
    public function testAcceptsValidConfidence(int|float $confidence): void
    {
        $result = self::comparisonResult([], 'no_clear_duplicate');
        $result['confidence'] = $confidence;

        self::assertSame($confidence, $this->validator()->validate($result, self::pool())['confidence']);
    }

    /**
     * @return iterable<string, array{int|float}>
     */
    public static function validConfidenceProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'fraction' => [0.95];
        yield 'one' => [1];
    }

    #[DataProvider('invalidConfidenceProvider')]
    public function testRejectsInvalidConfidence(mixed $confidence): void
    {
        $result = self::comparisonResult([], 'no_clear_duplicate');
        $result['confidence'] = $confidence;

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::pool());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidConfidenceProvider(): iterable
    {
        yield 'below zero' => [-0.01];
        yield 'above one' => [1.01];
        yield 'string' => ['0.95'];
        yield 'infinity' => [INF];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('missingOverlapProvider')]
    public function testRequiresOverlapForOverlapAssessments(string $assessment): void
    {
        $result = self::comparisonResult([
            self::outputCandidate('profile', $assessment, []),
        ]);

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::pool());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingOverlapProvider(): iterable
    {
        yield 'possible overlap' => ['possible_overlap'];
        yield 'likely duplicate' => ['likely_duplicate'];
    }

    #[DataProvider('invalidTextProvider')]
    public function testRejectsEmptyRequiredText(string $field): void
    {
        $result = self::comparisonResult([
            self::outputCandidate('profile', 'possible_overlap', ['profiles']),
        ]);

        if ($field === 'summary') {
            $result['summary'] = ' ';
        } elseif ($field === 'overlap') {
            $result['candidates'][0]['overlap'] = [' '];
        } elseif ($field === 'confirmed_differences') {
            $result['candidates'][0]['confirmed_differences'] = [' '];
        } else {
            $result['candidates'][0]['reason'] = ' ';
        }

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::pool());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTextProvider(): iterable
    {
        yield 'summary' => ['summary'];
        yield 'overlap' => ['overlap'];
        yield 'confirmed differences' => ['confirmed_differences'];
        yield 'reason' => ['reason'];
    }

    public function testRejectsInvalidAssessment(): void
    {
        $result = self::comparisonResult([
            self::outputCandidate('profile', 'duplicate', ['profiles']),
        ]);

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::pool());
    }

    #[DataProvider('invalidCandidatePoolProvider')]
    public function testRejectsInvalidCandidatePools(array $pool): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Module review comparison candidate pool is invalid.');

        $this->validator()->validate(self::comparisonResult([], 'no_clear_duplicate'), $pool);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidCandidatePoolProvider(): iterable
    {
        yield 'missing machine name' => [[[]]];
        yield 'empty machine name' => [[["machineName" => '']]];
        yield 'external whitespace' => [[["machineName" => ' profile ']]];
        yield 'duplicate machine name' => [[
            ['machineName' => 'profile'],
            ['machineName' => 'profile'],
        ]];
        yield 'candidate is not an array' => [['profile']];
        yield 'pool is not a list' => [[
            'profile' => ['machineName' => 'profile'],
        ]];
    }

    public function testIgnoresRrfMetadataAndDoesNotMutateInputs(): void
    {
        $result = self::comparisonResult([
            self::outputCandidate('profile', 'possible_overlap', ['profiles']),
        ]);
        $firstPool = [self::poolCandidate('profile', 0.01)];
        $secondPool = [self::poolCandidate('profile', 999.0)];
        $secondPool[0]['primaryMatches'] = [['term' => 'different', 'rank' => 99]];
        $secondPool[0]['secondaryMatches'] = [];
        $resultCopy = $result;
        $firstPoolCopy = $firstPool;

        $firstValidated = $this->validator()->validate($result, $firstPool);
        $secondValidated = $this->validator()->validate($result, $secondPool);

        self::assertSame($firstValidated, $secondValidated);
        self::assertSame($resultCopy, $result);
        self::assertSame($firstPoolCopy, $firstPool);
    }

    private function validator(): ModuleReviewComparisonResultValidator
    {
        return new ModuleReviewComparisonResultValidator();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pool(): array
    {
        return [
            self::poolCandidate('record', 0.9),
            self::poolCandidate('profile', 0.8),
            self::poolCandidate('professional_directory', 0.7),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function poolCandidate(string $machineName, float $score): array
    {
        return [
            'machineName' => $machineName,
            'title' => 'Title',
            'description' => 'Description',
            'url' => 'https://www.drupal.org/project/' . $machineName,
            'score' => $score,
            'primaryMatches' => [['term' => 'profile', 'rank' => 1]],
            'secondaryMatches' => [['term' => 'fitness', 'rank' => 2]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     *
     * @return array<string, mixed>
     */
    private static function comparisonResult(array $candidates, string $verdict = 'possible_overlap'): array
    {
        return [
            'verdict' => $verdict,
            'confidence' => 0.95,
            'summary' => 'Comparison summary.',
            'candidates' => $candidates,
        ];
    }

    /**
     * @param list<string> $overlap
     * @param list<string> $confirmedDifferences
     *
     * @return array<string, mixed>
     */
    private static function outputCandidate(
        string $machineName,
        string $assessment,
        array $overlap,
        array $confirmedDifferences = [],
        string $reason = 'Comparison reason.',
    ): array {
        return [
            'machine_name' => $machineName,
            'assessment' => $assessment,
            'overlap' => $overlap,
            'confirmed_differences' => $confirmedDifferences,
            'unverified_details' => [],
            'reason' => $reason,
        ];
    }

    private function expectInvalidResult(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI module comparison returned an invalid result.');
    }
}
