<?php

namespace mglaman\DrupalOrg\Tests\Formatter;

use mglaman\DrupalOrg\Result\Issue\IssueResult;
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use mglaman\DrupalOrgCli\Formatter\JsonFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonFormatter::class)]
class JsonFormatterTest extends TestCase
{
    private static function makeIssueResult(): IssueResult
    {
        return new IssueResult(
            nid: '3383637',
            title: 'Test issue title',
            created: 1693195104,
            changed: 1727653295,
            fieldIssueStatus: 1,
            fieldIssueCategory: 1,
            fieldIssuePriority: 200,
            fieldIssueVersion: '11.x-dev',
            fieldIssueComponent: 'Claro theme',
            fieldProjectMachineName: 'drupal',
            authorId: '3643629',
            bodyValue: '<p>Issue body content.</p>',
        );
    }

    public function testFormatProducesValidJson(): void
    {
        $formatter = new JsonFormatter();
        $output = $formatter->format(self::makeIssueResult());

        self::assertJson($output);
    }

    public function testFormatContainsExpectedFields(): void
    {
        $formatter = new JsonFormatter();
        $output = $formatter->format(self::makeIssueResult());

        $decoded = json_decode($output, true);
        self::assertIsArray($decoded);
        self::assertSame('3383637', $decoded['nid']);
        self::assertSame('Test issue title', $decoded['title']);
        self::assertSame('drupal', $decoded['field_project_machine_name']);
        self::assertSame(1, $decoded['field_issue_status']);
        self::assertSame(200, $decoded['field_issue_priority']);
    }

    public function testFormatIsPrettyPrinted(): void
    {
        $formatter = new JsonFormatter();
        $output = $formatter->format(self::makeIssueResult());

        self::assertStringContainsString("\n", $output);
    }

    public function testProjectMetadataResultIsSerialized(): void
    {
        $result = new ProjectMetadataResult(
            title: 'Example Module',
            machineName: 'example_module',
            projectType: 'module',
            coreVersionRequirement: '^11',
            dependencies: ['drupal:views'],
            summary: 'Summary.',
            description: 'Description.',
            features: ['Create records'],
            integrations: [],
            providerId: 'provider',
            modelId: 'model',
            tokenUsage: ['input' => 1, 'output' => 2, 'total' => 3],
        );

        $decoded = json_decode((new JsonFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Example Module', $decoded['title']);
        self::assertSame('example_module', $decoded['machine_name']);
        self::assertSame(['drupal:views'], $decoded['dependencies']);
    }
}
