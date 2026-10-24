<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\DrupalOrgProjectFormData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DrupalOrgProjectFormData::class)]
final class DrupalOrgProjectFormDataTest extends TestCase
{
    public function testSerializesTheVersionedFormShape(): void
    {
        $data = $this->formData(['Security', 'Content']);

        self::assertSame([
            'version' => 1,
            'title' => 'Example module',
            'machineName' => 'example_module',
            'projectType' => 'full',
            'summary' => 'A short summary.',
            'descriptionHtml' => '<p>Description.</p>',
            'maintenanceStatus' => 'Actively maintained',
            'developmentStatus' => 'Under active development',
            'categories' => ['Security', 'Content'],
        ], $data->toArray());
    }

    public function testRejectsMoreThanThreeCategories(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most three categories');

        $this->formData(['One', 'Two', 'Three', 'Four']);
    }

    public function testRejectsUnsupportedStatuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maintenance status');

        new DrupalOrgProjectFormData(
            'Example module',
            'example_module',
            'full',
            'A short summary.',
            '<p>Description.</p>',
            'Unknown status',
            'Under active development',
        );
    }

    public function testRejectsNonFullProjectType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('project type "full"');

        new DrupalOrgProjectFormData(
            'Example module',
            'example_module',
            'sandbox',
            'A short summary.',
            '<p>Description.</p>',
            'Actively maintained',
            'Under active development',
        );
    }

    /**
     * @param list<string> $categories
     */
    private function formData(array $categories = []): DrupalOrgProjectFormData
    {
        return new DrupalOrgProjectFormData(
            'Example module',
            'example_module',
            'full',
            'A short summary.',
            '<p>Description.</p>',
            'Actively maintained',
            'Under active development',
            $categories,
        );
    }
}
