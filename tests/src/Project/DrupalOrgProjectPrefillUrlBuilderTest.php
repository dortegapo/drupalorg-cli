<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\DrupalOrgProjectFormData;
use mglaman\DrupalOrg\Project\DrupalOrgProjectPrefillUrlBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DrupalOrgProjectPrefillUrlBuilder::class)]
final class DrupalOrgProjectPrefillUrlBuilderTest extends TestCase
{
    public function testBuildsBase64UrlEncodedVersionedFragment(): void
    {
        $data = $this->formData();

        $url = (new DrupalOrgProjectPrefillUrlBuilder())->build($data);

        self::assertStringStartsWith(
            DrupalOrgProjectPrefillUrlBuilder::PROJECT_CREATION_URL . '#'
            . DrupalOrgProjectPrefillUrlBuilder::FRAGMENT_PREFIX,
            $url,
        );
        self::assertStringNotContainsString('+', $url);
        self::assertStringNotContainsString('/', substr($url, strpos($url, '#')));
        $prefix = DrupalOrgProjectPrefillUrlBuilder::PROJECT_CREATION_URL . '#'
            . DrupalOrgProjectPrefillUrlBuilder::FRAGMENT_PREFIX;
        $encoded = substr($url, strlen($prefix));
        self::assertStringNotContainsString('=', $encoded);
        $json = base64_decode(
            strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4),
            true,
        );
        self::assertIsString($json);
        self::assertSame($data->toArray(), json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRejectsPayloadsThatExceedTheUrlLimit(): void
    {
        $data = new DrupalOrgProjectFormData(
            'Example module',
            'example_module',
            'full',
            str_repeat('summary ', 300),
            '<p>' . str_repeat('description ', 500) . '</p>',
            'Actively maintained',
            'Under active development',
        );

        $this->expectException(\LengthException::class);
        (new DrupalOrgProjectPrefillUrlBuilder())->build($data);
    }

    public function testFormDataMatchesTheBrowserCompanionPayloadFixture(): void
    {
        $contents = file_get_contents(
            dirname(__DIR__, 3) . '/browser-extension/test/fixtures/payload-v2.json',
        );
        self::assertIsString($contents);
        $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);

        $data = new DrupalOrgProjectFormData(
            $fixture['title'],
            $fixture['machineName'],
            $fixture['projectType'],
            $fixture['summary'],
            $fixture['descriptionHtml'],
            $fixture['maintenanceStatus'],
            $fixture['developmentStatus'],
            $fixture['categories'],
            $fixture['version'],
            $fixture['ecosystems'],
            $fixture['supportingOrganizations'],
            $fixture['issueComponents'],
            $fixture['enableIssueTracker'],
            $fixture['issueGuidelines'],
            $fixture['issueSummaryTemplate'],
            $fixture['homepage'],
            $fixture['changelog'],
            $fixture['demo'],
            $fixture['documentation'],
            $fixture['screenshots'],
        );

        self::assertSame($fixture, $data->toArray());
    }

    private function formData(): DrupalOrgProjectFormData
    {
        return new DrupalOrgProjectFormData(
            'Example module',
            'example_module',
            'full',
            'A short summary.',
            '<p>Description.</p>',
            'Actively maintained',
            'Under active development',
            ['Security'],
        );
    }
}
