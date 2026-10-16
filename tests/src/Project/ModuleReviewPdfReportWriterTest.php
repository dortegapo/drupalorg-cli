<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ModuleReviewPdfReportWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleReviewPdfReportWriter::class)]
final class ModuleReviewPdfReportWriterTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/drupalorg-cli-pdf-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workDir);
    }

    public function testWritesValidPdfForCompleteAnalysisAndReplacesExistingFile(): void
    {
        $targetPath = $this->workDir . '/my_module-drupalorg-contribution-review.pdf';
        file_put_contents($targetPath, 'old report');

        (new ModuleReviewPdfReportWriter())->write(
            $targetPath,
            self::completeAnalysis(),
            self::moduleInfo(),
        );

        $pdf = file_get_contents($targetPath);
        self::assertIsString($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(1000, strlen($pdf));
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::assertSame(0600, fileperms($targetPath) & 0777);
        }
    }

    public function testWritesValidPdfForIncompleteAnalysis(): void
    {
        $targetPath = $this->workDir . '/incomplete.pdf';
        $analysis = [
            'complete' => false,
            'source' => [
                'files' => 0,
                'bytes' => 0,
                'skipped' => [],
                'redactions' => [],
            ],
            'review' => null,
            'searches' => [],
            'candidates' => [],
            'comparison' => null,
            'assessment' => null,
            'ai' => [
                'review' => null,
                'comparison' => null,
            ],
            'limitations' => [[
                'stage' => 'project_search',
                'message' => 'Drupal.org project search could not be completed.',
            ]],
        ];

        (new ModuleReviewPdfReportWriter())->write($targetPath, $analysis, self::moduleInfo());

        $pdf = file_get_contents($targetPath);
        self::assertIsString($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertNull($analysis['assessment']);
    }

    public function testNullTokenUsageIsRenderedAsUnavailable(): void
    {
        $reflection = new \ReflectionMethod(ModuleReviewPdfReportWriter::class, 'buildHtml');
        $reflection->setAccessible(true);
        $html = $reflection->invoke(
            new ModuleReviewPdfReportWriter(),
            self::analysisWithNullTokenUsage(),
            self::moduleInfo(),
        );

        self::assertIsString($html);
        $aiSection = substr($html, (int) strpos($html, 'AI execution information'));
        self::assertStringContainsString('Unavailable', $aiSection);
        self::assertStringNotContainsString('<td>0</td>', $aiSection);
    }

    public function testRendersReadableDiscoveryAndComparisonSections(): void
    {
        $analysis = self::completeAnalysis();
        for ($index = 2; $index <= 12; $index++) {
            $analysis['candidates'][] = [
                'machineName' => 'project_' . $index,
                'title' => 'Project ' . $index,
                'description' => 'Project description.',
                'url' => 'https://www.drupal.org/project/project_' . $index,
                'score' => 0.01,
                'primaryMatches' => [],
                'secondaryMatches' => [],
            ];
        }
        $analysis['review']['reusability']['status'] = 'needs_generalization';
        $analysis['review']['contrib_readiness']['status'] = 'needs_changes';
        $analysis['comparison']['candidates'] = [
            [
                'machine_name' => 'project_2',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared record behavior.'],
                'differences' => ['Different domain.'],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'profiles',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared profile management.'],
                'differences' => ['Different configuration.'],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'project_3',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared record behavior.'],
                'differences' => ['Different domain.'],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'project_4',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared record behavior.'],
                'differences' => ['Different domain.'],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'project_5',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared record behavior.'],
                'differences' => ['Different domain.'],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'project_6',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared record behavior.'],
                'differences' => ['Different domain.'],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'project_7',
                'assessment' => 'possible_overlap',
                'overlap' => ['Shared record behavior.'],
                'differences' => ['Different domain.'],
                'reason' => 'The projects overlap partially.',
            ],
            [
                'machine_name' => 'project_8',
                'assessment' => 'possible_overlap',
                'overlap' => ['Shared record behavior.'],
                'differences' => ['Different domain.'],
                'reason' => 'The projects overlap partially.',
            ],
            [
                'machine_name' => 'project_9',
                'assessment' => 'related_not_duplicate',
                'overlap' => [],
                'differences' => [],
                'reason' => 'The projects are related but distinct.',
            ],
        ];
        $analysis['assessment'] = [
            'status' => 'not_candidate_yet',
            'signals' => [['code' => 'likely_duplicate', 'severity' => 'blocking']],
        ];

        $html = $this->renderHtml($analysis);

        self::assertStringContainsString('Search strategy', $html);
        self::assertStringContainsString('Module-name search', $html);
        self::assertStringContainsString('Core capability terms', $html);
        self::assertStringContainsString('Functional search queries', $html);
        self::assertStringContainsString('profile management', $html);
        self::assertStringContainsString('Domain-specific terms', $html);
        self::assertStringContainsString('These terms describe the reusable problem or capability implemented by the module', $html);
        self::assertStringContainsString('They are searched with lower ranking weight', $html);
        self::assertStringContainsString('Candidate discovery ranking', $html);
        self::assertStringContainsString('Weighted Reciprocal Rank Fusion (RRF)', $html);
        self::assertStringContainsString('not a duplicate probability', $html);
        self::assertStringContainsString('12 unique candidates discovered.', $html);
        self::assertStringContainsString('Top 10 shown below.', $html);
        self::assertStringContainsString('All discovered candidates were available to the semantic comparison stage.', $html);
        self::assertStringContainsString('<th>Rank</th><th>Project</th><th>Title</th><th>Matched queries</th>', $html);
        self::assertStringContainsString('profile (core)', $html);
        self::assertStringContainsString('customer (domain)', $html);
        self::assertStringContainsString('Project 10', $html);
        self::assertStringNotContainsString('Project 11', $html);
        self::assertStringNotContainsString('<th>Overlap</th>', $html);
        self::assertStringContainsString('comparison-card', $html);
        self::assertStringContainsString('Likely Duplicate', $html);
        self::assertStringContainsString('Possible Overlap', $html);
        self::assertStringContainsString('Related, Not Duplicate', $html);
        self::assertStringContainsString('Main signals', $html);
        self::assertStringContainsString('6 likely functional duplicates identified', $html);
        self::assertStringContainsString('Closest matches: Project 2, Profiles, Project 3', $html);
        self::assertStringContainsString('2 projects with possible functional overlap', $html);
        self::assertStringNotContainsString('Likely functional duplicate identified: Profiles', $html);
        self::assertStringContainsString('Needs Generalization', $html);
        self::assertStringContainsString('Needs Changes', $html);
        self::assertStringContainsString('Not Candidate Yet', $html);
        self::assertStringContainsString('Strong negative signal', $html);
        self::assertStringContainsString(
            'The following validated review signals were combined deterministically to produce the advisory contribution assessment.',
            $html
        );
        self::assertStringContainsString('Drupal coding standards: Not verified', $html);
        self::assertStringContainsString('AI execution information', $html);
        self::assertStringContainsString('Weighted Reciprocal Rank Fusion for candidate discovery ordering.', $html);
        self::assertStringContainsString('font-size:11pt', $html);
        self::assertStringContainsString('font-size:10.5pt', $html);
        self::assertStringContainsString('font-size:9.5pt', $html);
        self::assertStringNotContainsString('needs_generalization', $html);
        self::assertStringNotContainsString('not_candidate_yet', $html);

        self::assertCount(12, $analysis['candidates']);
    }

    public function testRendersCoreCompatibilitySeparatelyFromFunctionalAssessment(): void
    {
        $analysis = self::completeAnalysis();
        $analysis['core'] = [
            'runtimeVersion' => '11.4.8',
            'targetMajor' => 11,
        ];
        $analysis['candidateCompatibility'] = [
            'profiles' => [
                'status' => 'outdated',
                'targetCoreVersion' => '11.4.8',
                'targetCoreMajor' => 11,
                'supportedCoreMajors' => [10],
                'latestRelevantRelease' => '1.0.0',
                'declaredCompatibility' => ['^10'],
            ],
        ];
        $analysis['comparison']['candidates'][0]['assessment'] = 'likely_duplicate';

        $html = $this->renderHtml($analysis);

        self::assertStringContainsString('Runtime</dt><dd>Drupal 11.4.8', $html);
        self::assertStringContainsString('Target major</dt><dd>Drupal 11', $html);
        self::assertStringContainsString('Outdated for Drupal 11', $html);
        self::assertStringContainsString('Latest verified core support:</strong> Drupal 10', $html);
        self::assertStringContainsString('Contribution consideration', $html);
        self::assertStringContainsString('final functional-overlap assessment is performed separately', $html);
    }

    public function testWriterAcceptsOnlyStructuredAnalysisAndModuleMetadata(): void
    {
        $parameters = (new \ReflectionMethod(ModuleReviewPdfReportWriter::class, 'write'))->getParameters();

        self::assertSame(['targetPath', 'analysis', 'moduleInfo'], array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function completeAnalysis(): array
    {
        return [
            'complete' => true,
            'module' => ['machineName' => 'my_module', 'path' => '/private/module/path'],
            'source' => [
                'files' => 2,
                'bytes' => 1200,
                'skipped' => [['path' => '.env', 'reason' => 'sensitive']],
                'redactions' => [['path' => 'src/example.php', 'line' => 4, 'type' => 'token']],
            ],
            'review' => [
                'purpose' => 'Provides reusable profile management.',
                'capabilities' => ['Manage profiles.'],
                'drupal_concepts' => ['Content entities.'],
                'integrations' => ['Drupal user permissions.'],
                'primary_search_terms' => [['term' => 'profile', 'reason' => 'Core capability.']],
                'secondary_search_terms' => [['term' => 'customer', 'reason' => 'Observed domain.']],
                'functional_search_queries' => [['query' => 'profile management', 'reason' => 'Reusable interaction.']],
                'reusability' => [
                    'status' => 'reusable',
                    'confidence' => 0.9,
                    'findings' => [],
                ],
                'contrib_readiness' => [
                    'status' => 'ready',
                    'findings' => [],
                ],
                'analysis_limits' => [],
            ],
            'searches' => [
                ['type' => 'core', 'term' => 'profile', 'results' => []],
                ['type' => 'domain', 'term' => 'customer', 'results' => []],
            ],
            'candidates' => [[
                'machineName' => 'profiles',
                'title' => 'Profiles',
                'description' => 'Profile management.',
                'url' => 'https://www.drupal.org/project/profiles',
                'score' => 0.5,
                'primaryMatches' => [['term' => 'profile', 'rank' => 1]],
                'secondaryMatches' => [['term' => 'customer', 'rank' => 1]],
            ]],
            'comparison' => [
                'verdict' => 'possible_overlap',
                'confidence' => 0.7,
                'summary' => 'Some functionality overlaps.',
                'candidates' => [[
                    'machine_name' => 'profiles',
                    'assessment' => 'possible_overlap',
                    'overlap' => ['Profile management.'],
                    'differences' => ['Different scope.'],
                    'reason' => 'The projects address related needs.',
                ]],
            ],
            'assessment' => [
                'status' => 'candidate_with_changes',
                'signals' => [['code' => 'possible_overlap', 'severity' => 'warning']],
            ],
            'ai' => [
                'review' => [
                    'providerId' => 'test-provider',
                    'modelId' => 'test-model',
                    'tokenUsage' => ['input' => 10, 'output' => 5, 'total' => 15],
                ],
                'comparison' => [
                    'providerId' => 'test-provider',
                    'modelId' => 'test-model',
                    'tokenUsage' => ['input' => 20, 'output' => 10, 'total' => 30],
                ],
            ],
            'limitations' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function analysisWithNullTokenUsage(): array
    {
        $analysis = self::completeAnalysis();
        $analysis['ai']['review']['tokenUsage'] = [
            'input' => null,
            'output' => null,
            'total' => null,
        ];
        $analysis['ai']['comparison']['tokenUsage'] = [
            'input' => null,
            'output' => null,
            'total' => null,
        ];

        return $analysis;
    }

    /**
     * @return array{machineName: string, name: string, type: string, coreVersionRequirement: string}
     */
    private static function moduleInfo(): array
    {
        return [
            'machineName' => 'my_module',
            'name' => 'My Module',
            'type' => 'module',
            'coreVersionRequirement' => '^11',
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function renderHtml(array $analysis): string
    {
        $reflection = new \ReflectionMethod(ModuleReviewPdfReportWriter::class, 'buildHtml');
        $reflection->setAccessible(true);
        $html = $reflection->invoke(new ModuleReviewPdfReportWriter(), $analysis, self::moduleInfo());

        self::assertIsString($html);

        return $html;
    }

    private static function removeDirectory(string $directory): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
