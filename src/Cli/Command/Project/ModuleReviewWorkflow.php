<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrg\Project\DrupalAiBridge;
use mglaman\DrupalOrg\Project\DrupalRuntimeResolver;
use mglaman\DrupalOrg\Project\ModuleReviewAnalyzer;
use mglaman\DrupalOrg\Project\ModuleReviewAnalyzerFactory;
use mglaman\DrupalOrg\Project\ModuleReviewPdfReportWriter;
use mglaman\DrupalOrg\Project\ModuleReviewSourceSelector;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Project\ModuleReviewResult;
use mglaman\DrupalOrg\Project\PreparedModuleSource;
use mglaman\DrupalOrg\Project\SourceCoverage;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

final class ModuleReviewWorkflow
{
    private ?ModuleReviewAnalyzer $analyzer = null;

    /**
     * @param \Closure|null $runtimeResolver
     * @param \Closure|null $aiInspector
     * @param \Closure|null $sourcePreparer
     * @param \Closure|null $moduleAnalyzer
     * @param \Closure|null $pdfWriter
     * @param ProjectPublishProgressRenderer|null $progress
     */
    public function __construct(
        private readonly Client $client,
        private readonly ?\Closure $runtimeResolver = null,
        private readonly ?\Closure $aiInspector = null,
        private readonly ?\Closure $sourcePreparer = null,
        private readonly ?\Closure $moduleAnalyzer = null,
        private readonly ?\Closure $pdfWriter = null,
        private readonly ?ProjectPublishProgressRenderer $progress = null,
    ) {
    }

    public function run(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        ModuleContext $module,
    ): ModuleReviewWorkflowResult {
        $runtime = $this->resolveDrupalRuntime($module->location->projectRoot);
        if ($runtime === null) {
            $output->writeln('Drupal AI review is not available for this project environment.');

            return new ModuleReviewWorkflowResult(true, null, null);
        }

        try {
            $aiContext = $this->inspectDrupalAi($runtime);
        } catch (\RuntimeException) {
            $output->writeln('Drupal AI review is not available for this project environment.');

            return new ModuleReviewWorkflowResult(true, null, null);
        }

        if (!$aiContext['aiEnabled'] || $aiContext['providerId'] === null || $aiContext['modelId'] === null) {
            $output->writeln('Drupal AI review is not available for this project environment.');

            return new ModuleReviewWorkflowResult(true, null, null);
        }

        $this->writeConsent($output, $aiContext);
        if (!(bool) $helper->ask($input, $output, new ConfirmationQuestion(
            '<fg=yellow;options=bold>Run AI-assisted contribution review?</> [y/N] ',
            false,
        ))) {
            return new ModuleReviewWorkflowResult(true, $runtime, null);
        }

        $progress = $this->progress ?? new ProjectPublishProgressRenderer($output);
        $progress->registerReviewPhases();
        $progress->start(ProjectPublishProgressRenderer::PREPARING_SOURCE);
        try {
            $preparedSource = $this->prepareModuleSource($module->location->resolvedPath);
        } catch (\RuntimeException) {
            $progress->fail(ProjectPublishProgressRenderer::PREPARING_SOURCE);
            $progress->warning('Preparing module source... failed');

            return new ModuleReviewWorkflowResult(true, $runtime, null);
        }

        $selector = new ModuleReviewSourceSelector();
        $eligibleBytes = $preparedSource->totalBytes;
        $progress->complete(ProjectPublishProgressRenderer::PREPARING_SOURCE);
        if ($output->isVerbose()) {
            $progress->detail(sprintf(
                'Eligible source: %s across %d files.',
                $this->formatKilobytes($eligibleBytes),
                count($preparedSource->files),
            ));
        }

        $sourceBudget = null;
        if ($eligibleBytes > ModuleReviewSourceSelector::DEFAULT_BUDGET_BYTES) {
            $sourceBudget = $this->askSourceBudget($input, $output, $helper, $eligibleBytes);
            if ($sourceBudget === false) {
                $output->writeln('AI-assisted contribution review skipped.');

                return new ModuleReviewWorkflowResult(true, $runtime, null);
            }
        }

        $selection = $selector->select($preparedSource->files, $sourceBudget);
        $preparedSource = $preparedSource->withSelection(
            $selection['files'],
            $selection['selectedBytes'],
            SourceCoverage::fromArray($selection['coverage']),
        );
        if ($selection['coverage']['mode'] === 'selected') {
            if ($output->isVerbose()) {
                $output->writeln(sprintf(
                    '<comment>Selected source: %s across %d files.</comment>',
                    $this->formatKilobytes($selection['selectedBytes']),
                    count($selection['files']),
                ));
                $output->writeln(sprintf(
                    '<comment>Omitted due to source budget: %d files.</comment>',
                    count($selection['omitted']),
                ));
            }
        } elseif ($output->isVerbose()) {
            $output->writeln('<comment>Source mode: Full module.</comment>');
        }

        $progressRenderer = new ModuleReviewProgressRenderer($progress);
        $eventProgress = static function (string $event, array $context) use ($progressRenderer): void {
            $progressRenderer->handle($event, $context);
        };
        try {
            $analysis = $this->analyzeModule(
                $module->location->resolvedPath,
                $module->location->machineName,
                $runtime,
                $eventProgress,
                $module->info->name,
                $preparedSource,
            );
        } catch (\Throwable $exception) {
            $progressRenderer->finishOnFailure();
            throw $exception;
        }
        $progressRenderer->finishOnFailure($analysis);

        $pdfPath = null;
        $progress->start(ProjectPublishProgressRenderer::GENERATING_REVIEW_REPORT);
        try {
            $pdfPath = $this->writeModuleReviewPdf($analysis->toArray(), $module);
            $progress->complete(ProjectPublishProgressRenderer::GENERATING_REVIEW_REPORT);
        } catch (\Exception) {
            $progress->fail(ProjectPublishProgressRenderer::GENERATING_REVIEW_REPORT);
            $progress->warning('PDF report could not be generated.');
            $progress->warning('Review results are still available above.');
        }
        // The progress renderer owns a console section. Clear it before writing
        // the summary so the section is not emitted a second time around it.
        $progress->suspend();
        (new ModuleReviewSummaryRenderer($output))->render($analysis->toArray(), $pdfPath);

        $assessmentStatus = is_string($analysis->assessment['status'] ?? null)
            ? $analysis->assessment['status']
            : null;
        $continueDefault = in_array($assessmentStatus, ['candidate', 'candidate_with_changes'], true);
        $continuePrompt = $continueDefault
            ? '<fg=yellow;options=bold>Continue with project publishing?</> [Y/n] '
            : '<fg=yellow;options=bold>Continue with project publishing?</> [y/N] ';

        $output->writeln('');
        if (!(bool) $helper->ask($input, $output, new ConfirmationQuestion(
            $continuePrompt,
            $continueDefault,
        ))) {
            $output->writeln('Project publishing cancelled.');

            return new ModuleReviewWorkflowResult(
                false,
                $runtime,
                $analysis,
                $this->sourceCoverage($analysis),
            );
        }

        return new ModuleReviewWorkflowResult(
            true,
            $runtime,
            $analysis,
            $this->sourceCoverage($analysis),
        );
    }

    /**
     * @param array{aiEnabled: bool, providerId: ?string, modelId: ?string} $aiContext
     */
    private function writeConsent(OutputInterface $output, array $aiContext): void
    {
        $output->writeln('');
        $output->writeln('<fg=cyan;options=bold>AI CONTRIBUTION REVIEW</>');
        $output->writeln('');
        $output->writeln('Analyzes the selected module source and searches Drupal.org for');
        $output->writeln('potentially overlapping projects.');
        $output->writeln('');
        $output->writeln('The review is advisory only and may consume AI-provider tokens.');
        $output->writeln(sprintf('Provider: %s / %s', $aiContext['providerId'], $aiContext['modelId']));
        $output->writeln('Selected module source is sent to the configured AI provider.');
        $output->writeln('Local secret redaction is best effort and cannot guarantee complete removal.');
        if ($output->isVerbose()) {
            $output->writeln('');
            $output->writeln('<comment>Review details</comment>');
            $output->writeln('<comment>Two AI requests are used: characterization and candidate comparison.</comment>');
            $output->writeln('<comment>The candidate list comes from Drupal.org project search, not from the AI.</comment>');
            $output->writeln('<comment>A PDF report is generated locally from the structured results.</comment>');
        }
    }

    /** @param array<string, mixed> $result */
    private function writeModuleReviewPdf(array $result, ModuleContext $module): string
    {
        $targetPath = $this->moduleReviewPdfPath($module);
        if ($this->pdfWriter !== null) {
            return ($this->pdfWriter)($targetPath, $result, [
                'machineName' => $module->location->machineName,
                'name' => $module->info->name,
                'type' => $module->info->type,
                'coreVersionRequirement' => $module->info->coreVersionRequirement,
            ]);
        }

        (new ModuleReviewPdfReportWriter())->write($targetPath, $result, [
            'machineName' => $module->location->machineName,
            'name' => $module->info->name,
            'type' => $module->info->type,
            'coreVersionRequirement' => $module->info->coreVersionRequirement,
        ]);

        return $targetPath;
    }

    private function moduleReviewPdfPath(ModuleContext $module): string
    {
        $directory = $module->location->projectRoot;
        if ($directory === null || !is_dir($directory)) {
            $directory = getcwd();
        }
        if ($directory === false || $directory === '') {
            $directory = dirname($module->location->resolvedPath);
        }

        $directory = rtrim($directory, '/\\');
        if ($directory === '') {
            $directory = DIRECTORY_SEPARATOR;
        }

        return $directory . DIRECTORY_SEPARATOR . $module->location->machineName
            . '-drupalorg-contribution-review.pdf';
    }

    /**
     * @param array{type: 'ddev'|'lando'|'local-drush', workingDirectory: string, command: list<string>} $runtime
     *
     * @return array{aiEnabled: bool, providerId: ?string, modelId: ?string}
     */
    private function inspectDrupalAi(array $runtime): array
    {
        if ($this->aiInspector !== null) {
            return ($this->aiInspector)($runtime);
        }

        return (new DrupalAiBridge())->inspect($runtime);
    }

    /**
     * @return array{type: 'ddev'|'lando'|'local-drush', workingDirectory: string, command: list<string>}|null
     */
    private function resolveDrupalRuntime(?string $projectRoot): ?array
    {
        if ($this->runtimeResolver !== null) {
            return ($this->runtimeResolver)($projectRoot);
        }

        return (new DrupalRuntimeResolver())->resolve($projectRoot);
    }

    /**
     * @return PreparedModuleSource
     */
    private function prepareModuleSource(string $modulePath): PreparedModuleSource
    {
        if ($this->sourcePreparer !== null) {
            $preparedSource = ($this->sourcePreparer)($modulePath);

            return $preparedSource instanceof PreparedModuleSource
                ? $preparedSource
                : PreparedModuleSource::fromArray($preparedSource);
        }

        return $this->getAnalyzer()->prepareSource($modulePath);
    }

    /**
     * @param array{type: 'ddev'|'lando'|'local-drush', workingDirectory: string, command: list<string>} $runtime
     * @param PreparedModuleSource $preparedSource
     *
     * @return ModuleReviewResult
     */
    private function analyzeModule(
        string $modulePath,
        string $machineName,
        array $runtime,
        callable $progress,
        string $humanName,
        PreparedModuleSource $preparedSource,
    ): ModuleReviewResult {
        if ($this->moduleAnalyzer !== null) {
            $result = ($this->moduleAnalyzer)(
                $modulePath,
                $machineName,
                $runtime,
                $progress,
                $humanName,
                $preparedSource,
            );

            if ($result instanceof ModuleReviewResult) {
                return $result;
            }

            if (!is_array($result)) {
                throw new \RuntimeException('Module review analyzer returned an invalid result.');
            }

            return ModuleReviewResult::fromArray($result);
        }

        return $this->getAnalyzer()->analyze(
            $modulePath,
            $machineName,
            $runtime,
            $progress,
            $humanName,
            $preparedSource,
        );
    }

    private function getAnalyzer(): ModuleReviewAnalyzer
    {
        return $this->analyzer ??= (new ModuleReviewAnalyzerFactory($this->client))->create();
    }

    private function askSourceBudget(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        int $eligibleBytes,
    ): int|false|null {
        $optionLines = [];
        $choiceValues = [];
        $choiceNumber = 1;
        foreach (ModuleReviewSourceSelector::AVAILABLE_BUDGETS as $budget) {
            if ($budget >= $eligibleBytes) {
                continue;
            }

            $choice = (string) $choiceNumber;
            $kilobytes = intdiv($budget, 1024);
            $label = $choiceNumber === 1
                ? sprintf('%d KiB  Recommended · lowest token usage', $kilobytes)
                : sprintf('%d KiB  More coverage', $kilobytes);
            $optionLines[] = sprintf('  %s. %s', $choice, $label);
            $choiceValues[$choice] = $budget;
            ++$choiceNumber;
        }

        $fullChoice = (string) $choiceNumber;
        $optionLines[] = sprintf(
            '  %s. Full module (%s)  Highest coverage · may exceed model context',
            $fullChoice,
            $this->formatKilobytes($eligibleBytes),
        );
        $choiceValues[$fullChoice] = null;
        ++$choiceNumber;

        $cancelChoice = (string) $choiceNumber;
        $optionLines[] = sprintf('  %s. Cancel AI review', $cancelChoice);
        $choiceValues[$cancelChoice] = false;

        $question = new Question(
            "<fg=yellow;options=bold>Choose source coverage:</>\n\n"
            . implode("\n", $optionLines)
            . "\n\n<fg=yellow;options=bold>Selection [1]:</> ",
            '1',
        );
        $question->setValidator(static function (mixed $selected) use ($choiceValues): int|false|null {
            $selected = (string) $selected;
            if (!array_key_exists($selected, $choiceValues)) {
                throw new \InvalidArgumentException('Invalid selection. Choose one of the displayed options.');
            }

            return $choiceValues[$selected];
        });

        /** @var int|false|null $selected */
        $selected = $helper->ask($input, $output, $question);

        return $selected;
    }

    private function formatKilobytes(int $bytes): string
    {
        $value = number_format($bytes / 1024, 1, '.', '');

        return rtrim(rtrim($value, '0'), '.') . ' KiB';
    }

    private function sourceCoverage(ModuleReviewResult $analysisResult): string
    {
        $coverage = $analysisResult->source['coverage'] ?? null;

        return is_array($coverage) && ($coverage['mode'] ?? null) === 'selected' ? 'partial' : 'full';
    }
}
