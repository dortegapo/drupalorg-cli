<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\DrupalOrgProjectDescriptionBuilder;
use mglaman\DrupalOrg\Result\Project\ProjectMetadataResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DrupalOrgProjectDescriptionBuilder::class)]
final class DrupalOrgProjectDescriptionBuilderTest extends TestCase
{
    public function testBuildsDescriptionSectionsFromMetadata(): void
    {
        $metadata = $this->metadata(
            description: 'Stores <customer> records & related data.',
            features: ['Create "records"', 'List & filter records'],
            dependencies: ['drupal:views'],
            integrations: ['Drupal Field API'],
        );

        $html = (new DrupalOrgProjectDescriptionBuilder())->build($metadata);

        $expected = <<<'HTML'
<p>Stores &lt;customer&gt; records &amp; related data.</p>

<h3>Features</h3>
<ul>
  <li>Create &quot;records&quot;</li>
  <li>List &amp; filter records</li>
</ul>

<h3>Additional Requirements</h3>
<ul>
  <li>Requires: drupal:views</li>
</ul>

<h3>Integrations</h3>
<ul>
  <li>Drupal Field API</li>
</ul>
HTML;

        self::assertSame($expected, $html);
        self::assertStringNotContainsString('<customer>', $html);
    }

    public function testOmitsEmptyOptionalSections(): void
    {
        $html = (new DrupalOrgProjectDescriptionBuilder())->build(
            $this->metadata('Description.', [], [], []),
        );

        self::assertSame('<p>Description.</p>', $html);
        self::assertStringNotContainsString('Features', $html);
        self::assertStringNotContainsString('Additional Requirements', $html);
        self::assertStringNotContainsString('Integrations', $html);
    }

    /**
     * @param list<string> $features
     * @param list<string> $dependencies
     * @param list<string> $integrations
     */
    private function metadata(
        string $description = 'Description.',
        array $features = ['Create records'],
        array $dependencies = [],
        array $integrations = [],
    ): ProjectMetadataResult {
        return new ProjectMetadataResult(
            'Example module',
            'example_module',
            'module',
            '^11',
            $dependencies,
            'Summary.',
            $description,
            $features,
            $integrations,
            'provider',
            'model',
            ['input' => null, 'output' => null, 'total' => null],
        );
    }
}
