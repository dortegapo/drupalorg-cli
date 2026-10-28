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

    public function testTechnicalExecutionSectionsAreOmitted(): void
    {
        $reflection = new \ReflectionMethod(ModuleReviewPdfReportWriter::class, 'buildHtml');
        $reflection->setAccessible(true);
        $html = $reflection->invoke(
            new ModuleReviewPdfReportWriter(),
            self::analysisWithTechnicalMetadata(),
            self::moduleInfo(),
        );

        self::assertIsString($html);
        self::assertStringNotContainsString('AI execution information', $html);
        self::assertStringNotContainsString('Methodology', $html);
        self::assertStringNotContainsString('Assessment signals', $html);
        self::assertStringNotContainsString('Deterministic code-quality checks', $html);
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
        $analysis['review']['contrib_readiness']['required_changes'] = [
            [
                'severity' => 'high',
                'file' => 'src/example.php',
                'line' => 4,
                'evidence' => 'A project-specific endpoint is embedded in the module.',
                'reason' => 'The module cannot be reused independently without adaptation.',
                'suggestion' => 'Move the endpoint into configurable settings.',
            ],
        ];
        $analysis['comparison']['candidates'] = [
            [
                'machine_name' => 'project_2',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared record behavior.'],
                'confirmed_differences' => ['Different domain.'],
                'unverified_details' => ['The public metadata does not establish access filtering.'],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'profiles',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared profile management.'],
                'confirmed_differences' => ['Different configuration.'],
                'unverified_details' => [],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'project_3',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared record behavior.'],
                'confirmed_differences' => ['Different domain.'],
                'unverified_details' => [],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'project_4',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared record behavior.'],
                'confirmed_differences' => ['Different domain.'],
                'unverified_details' => [],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'project_5',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared record behavior.'],
                'confirmed_differences' => ['Different domain.'],
                'unverified_details' => [],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'project_6',
                'assessment' => 'likely_duplicate',
                'overlap' => ['Shared record behavior.'],
                'confirmed_differences' => ['Different domain.'],
                'unverified_details' => [],
                'reason' => 'The projects address substantially the same problem.',
            ],
            [
                'machine_name' => 'project_7',
                'assessment' => 'possible_overlap',
                'overlap' => ['Shared record behavior.'],
                'confirmed_differences' => ['Different domain.'],
                'unverified_details' => [],
                'reason' => 'The projects overlap partially.',
            ],
            [
                'machine_name' => 'project_8',
                'assessment' => 'possible_overlap',
                'overlap' => ['Shared record behavior.'],
                'confirmed_differences' => ['Different domain.'],
                'unverified_details' => [],
                'reason' => 'The projects overlap partially.',
            ],
            [
                'machine_name' => 'project_9',
                'assessment' => 'related_not_duplicate',
                'overlap' => [],
                'confirmed_differences' => [],
                'unverified_details' => [],
                'reason' => 'The projects are related but distinct.',
            ],
        ];
        $analysis['assessment'] = [
            'status' => 'not_candidate_yet',
            'signals' => [['code' => 'likely_duplicate', 'severity' => 'blocking']],
        ];

        $html = $this->renderHtml($analysis);

        self::assertStringContainsString('profile management', $html);
        self::assertStringNotContainsString('Drupal.org discovery', $html);
        self::assertStringNotContainsString('Candidate projects found:', $html);
        self::assertStringNotContainsString('Projects compared:', $html);
        self::assertStringNotContainsString('Search strategy', $html);
        self::assertStringNotContainsString('Candidate discovery ranking', $html);
        self::assertStringNotContainsString('Weighted Reciprocal Rank Fusion', $html);
        self::assertStringNotContainsString('<th>Rank</th>', $html);
        self::assertStringContainsString('comparison-card', $html);
        self::assertStringContainsString('Likely Duplicate', $html);
        self::assertStringContainsString('Possible Overlap', $html);
        self::assertStringContainsString('Related, Not Duplicate', $html);
        self::assertStringContainsString('Main signals', $html);
        self::assertStringContainsString('6 likely functional duplicates identified', $html);
        self::assertStringContainsString('Likely duplicate candidates: Project 2, Profiles, Project 3', $html);
        self::assertStringContainsString('2 projects with possible functional overlap', $html);
        self::assertStringNotContainsString('Likely functional duplicate identified: Profiles', $html);
        self::assertStringContainsString('Needs Generalization', $html);
        self::assertStringContainsString('Needs Changes', $html);
        self::assertStringContainsString('Confirmed differences', $html);
        self::assertStringContainsString('Unverified details', $html);
        self::assertStringContainsString('The public metadata does not establish access filtering.', $html);
        self::assertStringContainsString('Required changes before contribution', $html);
        self::assertStringContainsString('status-badge danger', $html);
        self::assertStringContainsString('Not Candidate Yet', $html);
        self::assertStringNotContainsString('Strong negative signal', $html);
        self::assertStringNotContainsString('The following validated review signals were combined deterministically', $html);
        self::assertStringNotContainsString('Drupal coding standards: Not verified', $html);
        self::assertStringNotContainsString('AI execution information', $html);
        self::assertStringNotContainsString('Methodology', $html);
        self::assertStringContainsString('font-size:11pt', $html);
        self::assertStringContainsString('font-size:10.5pt', $html);
        self::assertStringContainsString('font-size:9.5pt', $html);
        self::assertStringNotContainsString('needs_generalization', $html);
        self::assertStringNotContainsString('not_candidate_yet', $html);

        self::assertCount(12, $analysis['candidates']);
    }

    public function testRendersPartialSourceCoverageAndItsLimitation(): void
    {
        $analysis = self::completeAnalysis();
        $analysis['source']['coverage'] = [
            'mode' => 'selected',
            'requestedBudgetBytes' => 131072,
            'eligibleFiles' => 20,
            'eligibleBytes' => 395740,
            'selectedFiles' => 8,
            'selectedBytes' => 130000,
            'omittedFiles' => 12,
            'omittedBytes' => 265740,
            'omittedBreakdown' => [
                'tests' => 8,
                'styles' => 4,
            ],
        ];

        $html = $this->renderHtml($analysis);

        self::assertStringContainsString('Source coverage:</strong> Partial', $html);
        self::assertStringContainsString('Files analyzed:</strong> 8 of 20', $html);
        self::assertStringContainsString('Files omitted due to source budget:</strong> 12', $html);
        self::assertStringNotContainsString('Eligible files:', $html);
        self::assertStringNotContainsString('Selected budget:', $html);
        self::assertStringNotContainsString('Tests: 8', $html);
        self::assertStringNotContainsString('Styles: 4', $html);
        self::assertStringContainsString(
            'omitted files may contain additional evidence.',
            $html,
        );
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

        self::assertStringNotContainsString('Target Drupal core', $html);
        self::assertStringNotContainsString('Runtime</dt><dd>Drupal 11.4.8', $html);
        self::assertStringNotContainsString('Target major</dt><dd>Drupal 11', $html);
        self::assertStringContainsString('Outdated for Drupal 11', $html);
        self::assertStringContainsString('status-badge warning">Outdated for Drupal 11</span>', $html);
        self::assertStringContainsString('Latest verified core support:</strong> Drupal 10', $html);
        self::assertStringContainsString('Consider contributing core compatibility before creating a new project.', $html);
        self::assertStringNotContainsString('final functional-overlap assessment is performed separately', $html);

        $analysis['candidateCompatibility']['profiles']['status'] = 'compatible';
        $analysis['candidateCompatibility']['profiles']['supportedCoreMajors'] = [11];
        $compatibleHtml = $this->renderHtml($analysis);
        self::assertStringContainsString('status-badge success">Compatible with Drupal 11</span>', $compatibleHtml);
    }

    public function testRendersReadyAdvisoryNotesWithoutNeedsChangesLabel(): void
    {
        $html = $this->renderHtml(self::completeAnalysis());

        self::assertStringContainsString('Contribution readiness', $html);
        self::assertStringContainsString('status-badge success">Ready</span>', $html);
        self::assertStringContainsString('Advisory notes', $html);
        self::assertStringNotContainsString('Needs Changes', $html);
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
                    'required_changes' => [],
                    'advisory_notes' => [
                        [
                            'severity' => 'medium',
                            'file' => 'src/example.php',
                            'line' => 4,
                            'evidence' => 'The specialization is fixed in the current module.',
                            'reason' => 'This may be worth generalizing for broader reuse.',
                            'suggestion' => 'Consider making the specialization configurable.',
                        ],
                    ],
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
                    'confirmed_differences' => ['Different scope.'],
                    'unverified_details' => [],
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
    private static function analysisWithTechnicalMetadata(): array
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
