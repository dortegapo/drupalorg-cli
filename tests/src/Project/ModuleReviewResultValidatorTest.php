<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ModuleReviewResultValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleReviewResultValidator::class)]
class ModuleReviewResultValidatorTest extends TestCase
{
    public function testValidatesAndNormalizesCompleteResult(): void
    {
        $result = self::validResult();
        $result['purpose'] = '  Manages reusable profiles.  ';
        $result['capabilities'] = ['  Registers profiles.  '];
        $result['primary_search_terms'][0]['term'] = ' profile ';
        $result['primary_search_terms'][0]['reason'] = '  Reusable profile capability.  ';
        $result['reusability']['findings'][0]['file'] = ' src/Profile.php ';
        $result['reusability']['findings'][0]['evidence'] = '  A profile is registered.  ';
        $result['reusability']['findings'][0]['reason'] = '  The evidence is relevant.  ';
        $result['reusability']['findings'][0]['suggestion'] = '  Make the behavior configurable.  ';

        $validated = $this->validator()->validate($result, self::files());

        self::assertSame('Manages reusable profiles.', $validated['purpose']);
        self::assertSame(['Registers profiles.'], $validated['capabilities']);
        self::assertSame('profile', $validated['primary_search_terms'][0]['term']);
        self::assertSame('Reusable profile capability.', $validated['primary_search_terms'][0]['reason']);
        self::assertSame('src/Profile.php', $validated['reusability']['findings'][0]['file']);
        self::assertSame('A profile is registered.', $validated['reusability']['findings'][0]['evidence']);
        self::assertSame('The evidence is relevant.', $validated['reusability']['findings'][0]['reason']);
        self::assertSame('Make the behavior configurable.', $validated['reusability']['findings'][0]['suggestion']);
    }

    public function testPreservesTermOrderAndClassification(): void
    {
        $result = self::validResult();

        $validated = $this->validator()->validate($result, self::files());

        self::assertSame(
            ['profile', 'intake', 'registry'],
            array_column($validated['primary_search_terms'], 'term'),
        );
        self::assertSame(
            ['nutrition', 'fitness', 'allergy', 'medication'],
            array_column($validated['secondary_search_terms'], 'term'),
        );
    }

    #[DataProvider('invalidTermProvider')]
    public function testRejectsInvalidSearchTerms(string $location, string $term): void
    {
        $result = self::validResult();
        $result[$location][0]['term'] = $term;

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTermProvider(): iterable
    {
        yield 'uppercase primary' => ['primary_search_terms', 'Profile'];
        yield 'phrase primary' => ['primary_search_terms', 'customer profile'];
        yield 'hyphenated primary' => ['primary_search_terms', 'customer-profile'];
        yield 'underscored primary' => ['primary_search_terms', 'customer_profile'];
        yield 'numeric primary' => ['primary_search_terms', 'crm2'];
        yield 'uppercase secondary' => ['secondary_search_terms', 'Fitness'];
        yield 'phrase secondary' => ['secondary_search_terms', 'customer profile'];
        yield 'client primary' => ['primary_search_terms', 'client'];
        yield 'client secondary' => ['secondary_search_terms', 'client'];
    }

    public function testTrimsTermAndReasonBeforeValidation(): void
    {
        $result = self::validResult();
        $result['primary_search_terms'][0] = [
            'term' => ' profile ',
            'reason' => ' reusable capability ',
        ];

        $validated = $this->validator()->validate($result, self::files());

        self::assertSame(
            ['term' => 'profile', 'reason' => 'reusable capability'],
            $validated['primary_search_terms'][0],
        );
    }

    public function testRejectsDuplicatePrimaryTerms(): void
    {
        $result = self::validResult();
        $result['primary_search_terms'][1]['term'] = 'profile';

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    public function testRejectsDuplicateSecondaryTerms(): void
    {
        $result = self::validResult();
        $result['secondary_search_terms'][1]['term'] = 'nutrition';

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    public function testRejectsDuplicateAcrossPrimaryAndSecondaryTerms(): void
    {
        $result = self::validResult();
        $result['secondary_search_terms'][0]['term'] = 'profile';

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    public function testAllowsZeroSecondaryTerms(): void
    {
        $result = self::validResult();
        $result['secondary_search_terms'] = [];

        $validated = $this->validator()->validate($result, self::files());

        self::assertSame([], $validated['secondary_search_terms']);
    }

    public function testRejectsMissingPrimaryTerms(): void
    {
        $result = self::validResult();
        $result['primary_search_terms'] = [];

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    public function testRejectsMoreThanFivePrimaryTerms(): void
    {
        $result = self::validResult();
        $result['primary_search_terms'] = self::terms(['one', 'two', 'three', 'four', 'five', 'six']);

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    public function testRejectsMoreThanFiveSecondaryTerms(): void
    {
        $result = self::validResult();
        $result['secondary_search_terms'] = self::terms(['one', 'two', 'three', 'four', 'five', 'six']);

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    #[DataProvider('validConfidenceProvider')]
    public function testAcceptsValidConfidence(int|float $confidence): void
    {
        $result = self::validResult();
        $result['reusability']['confidence'] = $confidence;

        $validated = $this->validator()->validate($result, self::files());

        self::assertSame($confidence, $validated['reusability']['confidence']);
    }

    /**
     * @return iterable<string, array{int|float}>
     */
    public static function validConfidenceProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'fraction' => [0.77];
        yield 'one' => [1];
    }

    #[DataProvider('invalidConfidenceProvider')]
    public function testRejectsInvalidConfidence(int|float $confidence): void
    {
        $result = self::validResult();
        $result['reusability']['confidence'] = $confidence;

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    /**
     * @return iterable<string, array{int|float}>
     */
    public static function invalidConfidenceProvider(): iterable
    {
        yield 'below zero' => [-0.01];
        yield 'above one' => [1.01];
        yield 'infinity' => [INF];
        yield 'not a number' => [NAN];
    }

    public function testRejectsStringConfidence(): void
    {
        $result = self::validResult();
        $result['reusability']['confidence'] = '0.77';

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    public function testValidatesFindingFileAndLineReferences(): void
    {
        $result = self::validResult();
        $result['reusability']['findings'][0]['file'] = ' src/Profile.php ';
        $result['reusability']['findings'][0]['line'] = 2;

        $validated = $this->validator()->validate($result, self::files());

        self::assertSame('src/Profile.php', $validated['reusability']['findings'][0]['file']);
        self::assertSame(2, $validated['reusability']['findings'][0]['line']);
    }

    public function testAcceptsFirstAndLastLineWithCrLfAndFinalNewline(): void
    {
        $files = [
            [
                'path' => 'src/Profile.php',
                'bytes' => 13,
                'content' => "alpha\r\nbeta\r\n",
            ],
        ];
        $result = self::validResult();
        $result['reusability']['findings'][0]['line'] = 1;

        $this->validator()->validate($result, $files);

        $result['reusability']['findings'][0]['line'] = 2;
        $this->validator()->validate($result, $files);

        self::assertTrue(true);
    }

    public function testRejectsFindingWithUnknownFile(): void
    {
        $result = self::validResult();
        $result['reusability']['findings'][0]['file'] = 'src/Unknown.php';

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    #[DataProvider('invalidLineProvider')]
    public function testRejectsInvalidFindingLine(int $line): void
    {
        $result = self::validResult();
        $result['reusability']['findings'][0]['line'] = $line;

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidLineProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'greater than file' => [3];
    }

    public function testRejectsFindingForEmptyFile(): void
    {
        $result = self::validResult();
        $result['reusability']['findings'][0]['file'] = 'empty.txt';

        $this->expectInvalidResult();
        $this->validator()->validate($result, [
            [
                'path' => 'empty.txt',
                'bytes' => 0,
                'content' => '',
            ],
        ]);
    }

    #[DataProvider('emptyTextProvider')]
    public function testRejectsRequiredEmptyText(string $field): void
    {
        $result = self::validResult();
        switch ($field) {
            case 'purpose':
                $result['purpose'] = '  ';
                break;
            case 'capability':
                $result['capabilities'][0] = '  ';
                break;
            case 'term reason':
                $result['primary_search_terms'][0]['reason'] = '  ';
                break;
            case 'finding evidence':
                $result['reusability']['findings'][0]['evidence'] = '  ';
                break;
        }

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyTextProvider(): iterable
    {
        yield 'purpose' => ['purpose'];
        yield 'capability' => ['capability'];
        yield 'search term reason' => ['term reason'];
        yield 'finding evidence' => ['finding evidence'];
    }

    public function testRejectsInvalidReusabilityStatus(): void
    {
        $result = self::validResult();
        $result['reusability']['status'] = 'maybe';

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    public function testRejectsInvalidContribReadinessStatus(): void
    {
        $result = self::validResult();
        $result['contrib_readiness']['status'] = 'maybe';

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    public function testRejectsInvalidFindingSeverity(): void
    {
        $result = self::validResult();
        $result['reusability']['findings'][0]['severity'] = 'critical';

        $this->expectInvalidResult();
        $this->validator()->validate($result, self::files());
    }

    /**
     * @return array<string, mixed>
     */
    private static function validResult(): array
    {
        return [
            'purpose' => 'Manages reusable profiles.',
            'capabilities' => ['Registers profiles.'],
            'drupal_concepts' => ['content entities'],
            'integrations' => [],
            'primary_search_terms' => self::terms(['profile', 'intake', 'registry']),
            'secondary_search_terms' => self::terms(['nutrition', 'fitness', 'allergy', 'medication']),
            'reusability' => [
                'status' => 'reusable',
                'confidence' => 0.77,
                'findings' => [
                    [
                        'severity' => 'low',
                        'file' => 'src/Profile.php',
                        'line' => 1,
                        'evidence' => 'A profile is registered.',
                        'reason' => 'The evidence is relevant.',
                        'suggestion' => 'Make the behavior configurable.',
                    ],
                ],
            ],
            'contrib_readiness' => [
                'status' => 'ready',
                'findings' => [],
            ],
            'analysis_limits' => [],
        ];
    }

    /**
     * @param list<string> $terms
     *
     * @return list<array{term: string, reason: string}>
     */
    private static function terms(array $terms): array
    {
        return array_map(
            static fn (string $term): array => [
                'term' => $term,
                'reason' => 'Supported by the supplied source.',
            ],
            $terms,
        );
    }

    /**
     * @return list<array{path: string, bytes: int, content: string}>
     */
    private static function files(): array
    {
        return [
            [
                'path' => 'src/Profile.php',
                'bytes' => 24,
                'content' => "<?php\nfinal class Profile {}",
            ],
        ];
    }

    private function validator(): ModuleReviewResultValidator
    {
        return new ModuleReviewResultValidator();
    }

    private function expectInvalidResult(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drupal AI module review returned an invalid result.');
    }
}
