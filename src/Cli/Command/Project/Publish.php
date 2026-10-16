<?php

namespace mglaman\DrupalOrgCli\Command\Project;

use GuzzleHttp\Exception\RequestException;
use mglaman\DrupalOrg\Project\ComposerContextLocator;
use mglaman\DrupalOrg\Project\DrupalAiBridge;
use mglaman\DrupalOrg\Project\DrupalRuntimeResolver;
use mglaman\DrupalOrg\Project\ModuleInfoReader;
use mglaman\DrupalOrg\Project\ModuleLocator;
use mglaman\DrupalOrg\Project\ModuleReviewAnalyzerFactory;
use mglaman\DrupalOrg\Project\ModuleReviewPdfReportWriter;
use mglaman\DrupalOrgCli\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Publish extends Command
{

    protected function configure(): void
    {
        $this
            ->setName('project:publish')
            ->addArgument('module', InputArgument::REQUIRED, 'The module path')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be done without making publication changes.'
            )
            ->setDescription('Publishes a project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modulePath = (string) $input->getArgument('module');

        try {
            $module = (new ModuleLocator(new ComposerContextLocator()))->locate($modulePath);
        } catch (\RuntimeException $exception) {
            $this->stdErr->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return self::FAILURE;
        }

        $infoFile = $module['infoFile'];

        try {
            $moduleInfo = (new ModuleInfoReader())->read($infoFile, $module['moduleName']);
        } catch (\RuntimeException $exception) {
            $this->stdErr->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return self::FAILURE;
        }

        try {
            $project = $this->client->getProject($module['moduleName']);
        } catch (RequestException $exception) {
            if ($exception->getResponse()?->getStatusCode() !== 404) {
                $this->stdErr->writeln(sprintf(
                    '<error>Could not check Drupal.org project: %s</error>',
                    $exception->getMessage()
                ));
                return self::FAILURE;
            }
            $project = null;
        } catch (\Exception $exception) {
            $this->stdErr->writeln(sprintf(
                '<error>Could not check Drupal.org project: %s</error>',
                $exception->getMessage()
            ));
            return self::FAILURE;
        }

        if ((bool) $input->getOption('dry-run')) {
            $this->stdOut->writeln('Dry run: no changes will be made.');
            $this->stdOut->writeln('');
            $this->writeModuleContext($module, $moduleInfo);
            $this->stdOut->writeln(sprintf(
                'Drupal.org project exists: %s',
                $project === null ? 'no' : 'yes'
            ));
            $this->stdOut->writeln('');
            $this->stdOut->writeln('Planned actions:');

            $plannedActions = $project === null
                ? [
                    'Authenticate with Drupal.org automatically if required.',
                    'Offer an optional AI-assisted contribution review and local PDF report.',
                    sprintf('Create Drupal.org project "%s".', $module['moduleName']),
                    'Wait for repository provisioning.',
                    'Prepare an isolated temporary workspace.',
                    'Copy the module into the temporary workspace.',
                    'Initialize the publication Git repository.',
                    'Create the initial commit.',
                    'Configure the Drupal.org Git remote.',
                    'Push the initial branch.',
                ]
                : [
                    sprintf('Use the existing Drupal.org project "%s".', $module['moduleName']),
                    'Prepare an isolated temporary workspace.',
                    'Copy the module into the temporary workspace.',
                    'Initialize the publication Git repository.',
                    'Create the initial commit.',
                    'Configure the Drupal.org Git remote.',
                    'Push the initial branch.',
                ];

            foreach ($plannedActions as $index => $plannedAction) {
                $this->stdOut->writeln(sprintf('  %d. %s', $index + 1, $plannedAction));
            }

            $this->stdOut->writeln('');
            $this->stdOut->writeln('Publication execution is not implemented yet.');
            $this->stdOut->writeln('Dry run complete. No changes were made.');

            return self::SUCCESS;
        }

        $this->stdOut->writeln('');
        $this->stdOut->writeln('Module');
        $this->stdOut->writeln('');
        $this->writeModuleContext($module, $moduleInfo);
        $this->stdOut->writeln('');
        $this->stdOut->writeln(sprintf(
            'Drupal.org project %s: %s',
            $project === null ? 'does not exist' : 'exists',
            $module['moduleName']
        ));

        if ($project === null && $input->isInteractive()) {
            if (!$this->offerModuleReview($input, $output, $module, $moduleInfo)) {
                return self::SUCCESS;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Offer the advisory AI review for a new Drupal.org project candidate.
     *
     * @param array{
     *     resolvedPath: string,
     *     moduleName: string,
     *     infoFile: string,
     *     projectRoot: ?string,
     *     webRoot: ?string
     * } $module
     * @param array{name: string, type: string, coreVersionRequirement: string} $moduleInfo
     */
    protected function offerModuleReview(
        InputInterface $input,
        OutputInterface $output,
        array $module,
        array $moduleInfo,
    ): bool {
        $runtime = $this->resolveDrupalRuntime($module['projectRoot']);
        if ($runtime === null) {
            $this->stdOut->writeln('Drupal AI review is not available for this project environment.');
            return true;
        }

        try {
            $aiContext = $this->inspectDrupalAi($runtime);
        } catch (\RuntimeException) {
            $this->stdOut->writeln('Drupal AI review is not available for this project environment.');
            return true;
        }

        if (!$aiContext['aiEnabled'] || $aiContext['providerId'] === null || $aiContext['modelId'] === null) {
            $this->stdOut->writeln('Drupal AI review is not available for this project environment.');
            return true;
        }

        $this->writeModuleReviewConsent($aiContext);

        if (!$this->askConfirmation($input, $output, 'Run AI-assisted contribution review? [y/N] ', false)) {
            return true;
        }

        $renderer = new ModuleReviewProgressRenderer($output);
        $progress = static function (string $event, array $context) use ($renderer): void {
            $renderer->handle($event, $context);
        };
        try {
            $result = $this->analyzeModule(
                $module['resolvedPath'],
                $module['moduleName'],
                $runtime,
                $progress,
                $moduleInfo['name'],
            );
        } finally {
            $renderer->finishOnFailure();
        }

        $pdfPath = null;
        $this->stdOut->writeln('Generating PDF report...');
        try {
            $pdfPath = $this->writeModuleReviewPdf($result, $module, $moduleInfo);
            $this->stdOut->writeln('PDF report generated.');
        } catch (\Throwable) {
            $this->stdOut->writeln('<comment>Could not generate the PDF review report.</comment>');
            $this->stdOut->writeln('The AI review results are still available in the terminal summary.');
        }
        $this->writeModuleReviewSummary($result, $pdfPath);

        if (!$this->askConfirmation($input, $output, 'Continue with project publishing? [y/N] ', false)) {
            $this->stdOut->writeln('Project publishing cancelled.');
            return false;
        }

        return true;
    }

    /**
     * @param array{
     *     type: 'ddev'|'lando'|'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * } $runtime
     *
     * @return array{
     *     aiEnabled: bool,
     *     providerId: ?string,
     *     modelId: ?string
     * }
     */
    protected function inspectDrupalAi(array $runtime): array
    {
        return (new DrupalAiBridge())->inspect($runtime);
    }

    /**
     * @return array{
     *     type: 'ddev'|'lando'|'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * }|null
     */
    protected function resolveDrupalRuntime(?string $projectRoot): ?array
    {
        return (new DrupalRuntimeResolver())->resolve($projectRoot);
    }

    /**
     * @param array{
     *     type: 'ddev'|'lando'|'local-drush',
     *     workingDirectory: string,
     *     command: list<string>
     * } $runtime
     *
     * @return array<string, mixed>
     */
    protected function analyzeModule(
        string $modulePath,
        string $machineName,
        array $runtime,
        ?callable $progress = null,
        ?string $humanName = null,
    ): array {
        return (new ModuleReviewAnalyzerFactory($this->client))
            ->create()
            ->analyze($modulePath, $machineName, $runtime, $progress, $humanName);
    }

    /**
     * @param array<string, mixed> $result
     * @param array{
     *     resolvedPath: string,
     *     moduleName: string,
     *     infoFile: string,
     *     projectRoot: ?string,
     *     webRoot: ?string
     * } $module
     * @param array{
     *     name: string,
     *     type: string,
     *     coreVersionRequirement: string
     * } $moduleInfo
     */
    protected function writeModuleReviewPdf(array $result, array $module, array $moduleInfo): string
    {
        $targetPath = $this->moduleReviewPdfPath($module);
        (new ModuleReviewPdfReportWriter())->write($targetPath, $result, [
            'machineName' => $module['moduleName'],
            'name' => $moduleInfo['name'],
            'type' => $moduleInfo['type'],
            'coreVersionRequirement' => $moduleInfo['coreVersionRequirement'],
        ]);

        return $targetPath;
    }

    protected function askConfirmation(
        InputInterface $input,
        OutputInterface $output,
        string $question,
        bool $default
    ): bool {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            throw new \LogicException('The question helper is not available.');
        }

        return (bool) $helper->ask($input, $output, new ConfirmationQuestion($question, $default));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function writeModuleReviewSummary(array $result, ?string $pdfPath = null): void
    {
        $this->stdOut->writeln('');
        $this->stdOut->writeln('AI-assisted contribution review');

        if (($result['complete'] ?? false) !== true) {
            $limitation = $result['limitations'][0] ?? [];
            $stage = is_array($limitation) && is_string($limitation['stage'] ?? null)
                ? $limitation['stage']
                : 'unknown';
            $message = is_array($limitation) && is_string($limitation['message'] ?? null)
                ? $limitation['message']
                : 'The AI module review could not be completed.';
            $this->stdOut->writeln('AI module review could not be completed.');
            $this->stdOut->writeln(sprintf('Stage: %s', $stage));
            $this->stdOut->writeln($message);
            $this->stdOut->writeln('The publication flow can continue without the AI review.');
            $this->writePdfPath($pdfPath);
            return;
        }

        $assessment = is_array($result['assessment'] ?? null) ? $result['assessment'] : [];
        $assessmentStatus = is_string($assessment['status'] ?? null) ? $assessment['status'] : 'unknown';
        $assessmentLabels = [
            'candidate' => 'Candidate for contribution',
            'candidate_with_changes' => 'Candidate with changes',
            'not_candidate_yet' => 'Not a candidate yet',
        ];
        $this->stdOut->writeln(sprintf(
            'Assessment: %s',
            $assessmentLabels[$assessmentStatus] ?? $assessmentStatus
        ));

        $review = is_array($result['review'] ?? null) ? $result['review'] : [];
        $reusability = is_array($review['reusability'] ?? null) ? $review['reusability'] : [];
        $readiness = is_array($review['contrib_readiness'] ?? null) ? $review['contrib_readiness'] : [];
        $this->stdOut->writeln(sprintf('Reusability: %s', (string) ($reusability['status'] ?? 'unknown')));
        $this->stdOut->writeln(sprintf('Contrib readiness: %s', (string) ($readiness['status'] ?? 'unknown')));

        $comparison = is_array($result['comparison'] ?? null) ? $result['comparison'] : [];
        $this->stdOut->writeln(sprintf('Drupal.org overlap: %s', (string) ($comparison['verdict'] ?? 'unknown')));
        $candidates = is_array($result['candidates'] ?? null) ? $result['candidates'] : [];
        $this->stdOut->writeln(sprintf('Candidate projects evaluated: %d', count($candidates)));
        $source = is_array($result['source'] ?? null) ? $result['source'] : [];
        $redactions = is_array($source['redactions'] ?? null) ? $source['redactions'] : [];
        $this->stdOut->writeln(sprintf('Sensitive values redacted locally: %d', count($redactions)));

        $this->writeAiMetadata('AI #1', $result['ai']['review'] ?? null);
        $this->writeAiMetadata('AI #2', $result['ai']['comparison'] ?? null);

        $tokenUsage = $this->sumTokenUsage($result['ai'] ?? null);
        if ($tokenUsage === null) {
            $this->stdOut->writeln('Tokens used: unavailable');
        } else {
            $this->stdOut->writeln(sprintf(
                'Tokens used: input %s, output %s, total %s',
                $this->formatTokenValue($tokenUsage['input']),
                $this->formatTokenValue($tokenUsage['output']),
                $this->formatTokenValue($tokenUsage['total']),
            ));
        }

        $this->writePotentialOverlaps($comparison);
        $this->writePdfPath($pdfPath);
    }

    /**
     * @param array{
     *     resolvedPath: string,
     *     moduleName: string,
     *     infoFile: string,
     *     projectRoot: ?string,
     *     webRoot: ?string
     * } $module
     * @param array{name: string, type: string, coreVersionRequirement: string} $moduleInfo
     */
    private function writeModuleContext(array $module, array $moduleInfo): void
    {
        $this->stdOut->writeln(sprintf('Module path: %s', $module['resolvedPath']));
        $this->stdOut->writeln(sprintf('Machine name: %s', $module['moduleName']));
        $this->stdOut->writeln(sprintf('Module info file: %s', $module['infoFile']));
        $this->stdOut->writeln(sprintf('Module name: %s', $moduleInfo['name']));
        $this->stdOut->writeln(sprintf('Module type: %s', $moduleInfo['type']));
        $this->stdOut->writeln(sprintf(
            'Core version requirement: %s',
            $moduleInfo['coreVersionRequirement']
        ));
    }

    /**
     * @param array{aiEnabled: bool, providerId: ?string, modelId: ?string} $aiContext
     */
    private function writeModuleReviewConsent(array $aiContext): void
    {
        $this->stdOut->writeln('');
        $this->stdOut->writeln('Optional AI-assisted contribution review');
        $this->stdOut->writeln('');
        $this->stdOut->writeln('Before publishing, drupalorg-cli can analyze this module to:');
        $this->stdOut->writeln('  - understand its purpose and reusable capabilities;');
        $this->stdOut->writeln('  - assess whether it is reusable enough for Drupal.org contribution;');
        $this->stdOut->writeln('  - identify changes that may be needed before contributing it;');
        $this->stdOut->writeln('  - search Drupal.org for existing projects with similar functionality;');
        $this->stdOut->writeln('  - compare those projects with this module for possible functional overlap;');
        $this->stdOut->writeln('  - generate a PDF report with the findings.');
        $this->stdOut->writeln('');
        $this->stdOut->writeln('The review normally uses two AI requests:');
        $this->stdOut->writeln('  1. Module analysis.');
        $this->stdOut->writeln('  2. Semantic comparison against Drupal.org candidates.');
        $this->stdOut->writeln('');
        $this->stdOut->writeln('Drupal.org project searches are performed directly against Drupal.org.');
        $this->stdOut->writeln('The AI does not browse Drupal.org or invent the candidate project list.');
        $this->stdOut->writeln('');
        $this->stdOut->writeln('Only the selected module is analyzed.');
        $this->stdOut->writeln('Its source code will be sent to the configured Drupal AI provider:');
        $this->stdOut->writeln(sprintf('  Provider: %s', $aiContext['providerId']));
        $this->stdOut->writeln(sprintf('  Model: %s', $aiContext['modelId']));
        $this->stdOut->writeln('');
        $this->stdOut->writeln('The CLI excludes known sensitive files and performs best-effort local redaction');
        $this->stdOut->writeln('of common secrets before sending source code. Files from other modules, vendor,');
        $this->stdOut->writeln('or unrelated project directories are not intentionally included.');
        $this->stdOut->writeln('');
        $this->stdOut->writeln('The PDF is generated locally from the structured review results.');
        $this->stdOut->writeln('Generating the PDF itself does not make an additional AI request.');
        $this->stdOut->writeln('');
        $this->stdOut->writeln('The analysis may consume tokens from your configured AI provider.');
        $this->stdOut->writeln('The review is advisory only. Its result will not prevent you from continuing with project publishing.');
    }

    private function writePdfPath(?string $pdfPath): void
    {
        if ($pdfPath !== null) {
            $this->stdOut->writeln('');
            $this->stdOut->writeln(sprintf('Full report: %s', $pdfPath));
        }
    }

    /**
     * @param array{
     *     resolvedPath: string,
     *     moduleName: string,
     *     infoFile: string,
     *     projectRoot: ?string,
     *     webRoot: ?string
     * } $module
     */
    private function moduleReviewPdfPath(array $module): string
    {
        $directory = $module['projectRoot'];
        if ($directory === null || !is_dir($directory)) {
            $directory = getcwd();
        }
        if ($directory === false || $directory === '') {
            $directory = dirname($module['resolvedPath']);
        }

        $directory = rtrim($directory, '/\\');
        if ($directory === '') {
            $directory = DIRECTORY_SEPARATOR;
        }

        return $directory . DIRECTORY_SEPARATOR . $module['moduleName']
            . '-drupalorg-contribution-review.pdf';
    }

    /**
     * @param mixed $metadata
     */
    private function writeAiMetadata(string $label, mixed $metadata): void
    {
        if (!is_array($metadata)) {
            return;
        }

        $provider = is_string($metadata['providerId'] ?? null) ? $metadata['providerId'] : 'unknown';
        $model = is_string($metadata['modelId'] ?? null) ? $metadata['modelId'] : 'unknown';
        $this->stdOut->writeln(sprintf('%s: %s / %s', $label, $provider, $model));
    }

    /**
     * @param mixed $ai
     *
     * @return array{input: ?int, output: ?int, total: ?int}|null
     */
    private function sumTokenUsage(mixed $ai): ?array
    {
        if (!is_array($ai)) {
            return null;
        }

        $totals = ['input' => null, 'output' => null, 'total' => null];
        $found = false;
        foreach (['review', 'comparison'] as $stage) {
            $usage = $ai[$stage]['tokenUsage'] ?? null;
            if (!is_array($usage)) {
                continue;
            }
            foreach (array_keys($totals) as $key) {
                if (is_int($usage[$key] ?? null)) {
                    $totals[$key] = ($totals[$key] ?? 0) + $usage[$key];
                    $found = true;
                }
            }
        }

        return $found ? $totals : null;
    }

    private function formatTokenValue(?int $value): string
    {
        return $value === null ? 'unavailable' : (string) $value;
    }

    /**
     * @param array<string, mixed> $comparison
     */
    private function writePotentialOverlaps(array $comparison): void
    {
        $matches = $comparison['candidates'] ?? [];
        if (!is_array($matches) || $matches === []) {
            return;
        }

        $this->stdOut->writeln('Potential overlaps:');
        $shown = 0;
        foreach ($matches as $match) {
            if (!is_array($match) || !is_string($match['machine_name'] ?? null)) {
                continue;
            }

            $assessment = is_string($match['assessment'] ?? null) ? $match['assessment'] : 'unknown';
            if (!in_array($assessment, ['likely_duplicate', 'possible_overlap'], true)) {
                continue;
            }

            $this->stdOut->writeln(sprintf('  - %s (%s)', $match['machine_name'], $assessment));
            ++$shown;
            if ($shown === 5) {
                break;
            }
        }
    }
}
