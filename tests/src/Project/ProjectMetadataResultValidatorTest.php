<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ProjectMetadataResultValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectMetadataResultValidator::class)]
class ProjectMetadataResultValidatorTest extends TestCase
{
    public function testAcceptsValidMetadata(): void
    {
        $result = (new ProjectMetadataResultValidator())->validate([
            'summary' => 'Manages reusable profile records.',
            'description' => 'Provides profile record management for Drupal sites.',
            'features' => ['Create records', 'List records'],
            'integrations' => [],
            ...self::additionalMetadata(),
        ]);

        self::assertSame('Manages reusable profile records.', $result['summary']);
        self::assertSame(['Create records', 'List records'], $result['features']);
    }

    public function testRejectsUnknownOrMissingFields(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected or missing fields');
        (new ProjectMetadataResultValidator())->validate([
            'summary' => 'Summary',
            'description' => 'Description',
            'features' => [],
            'integrations' => [],
            'title' => 'Unexpected',
        ]);
    }

    public function testRejectsNonPlainTextAndInvalidLists(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plain text');
        (new ProjectMetadataResultValidator())->validate([
            'summary' => '<strong>Summary</strong>',
            'description' => 'Description',
            'features' => [],
            'integrations' => [],
            ...self::additionalMetadata(),
        ]);
    }

    public function testRejectsMoreThanTenFeatures(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too many items');
        (new ProjectMetadataResultValidator())->validate([
            'summary' => 'Summary',
            'description' => 'Description',
            'features' => array_fill(0, 11, 'Feature'),
            'integrations' => [],
            ...self::additionalMetadata(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function additionalMetadata(): array
    {
        return [
            'categories' => ['Site structure'],
            'ecosystem' => [],
            'supporting_organizations' => [],
            'issues' => [
                'components' => ['Code'],
                'submission_guidelines' => '',
                'summary_template' => '',
                'enable_tracker' => true,
            ],
            'resources' => [
                'homepage' => '',
                'changelog' => '',
                'demo' => '',
                'documentation' => [],
                'screenshots' => [],
            ],
        ];
    }
}
