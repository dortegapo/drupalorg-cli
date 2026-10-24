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
     */
    public function __construct(
        private readonly Client $client,
        private readonly ?\Closure $runtimeResolver = null,
        private readonly ?\Closure $aiInspector = null,
        private readonly ?\Closure $sourcePreparer = null,
        private readonly ?\Closure $moduleAnalyzer = null,
        private readonly ?\Closure $pdfWriter = null,
    ) {
    }

    /**
     * @param array{
     *     resolvedPath: string,
     *     moduleName: string,
     *     infoFile: string,
     *     projectRoot: ?string,
     *     webRoot: ?string
     * } $module
     * @param array{name: string, type: string, coreVersionRequirement: string, dependencies: list<string>} $moduleInfo
     */
    public function run(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        array $module,
        array $moduleInfo,
    ): ModuleReviewWorkflowResult {
        $runtime = $this->resolveDrupalRuntime($module['projectRoot']);
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
            'Run AI-assisted contribution review? [y/N] ',
            false,
        ))) {
            return new ModuleReviewWorkflowResult(true, $runtime, null);
        }

        try {
            $preparedSource = $this->prepareModuleSource($module['resolvedPath']);
        } catch (\RuntimeException) {
            $output->writeln('<comment>Preparing module source... failed</comment>');

            return new ModuleReviewWorkflowResult(true, $runtime, null);
        }

        $selector = new ModuleReviewSourceSelector();
        $eligibleBytes = $preparedSource['totalBytes'];
        $output->writeln('Preparing module source... done');
        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                '<comment>Eligible source: %s across %d files.</comment>',
                $this->formatKilobytes($eligibleBytes),
                count($preparedSource['files']),
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

        $selection = $selector->select($preparedSource['files'], $sourceBudget);
        $preparedSource['files'] = $selection['files'];
        $preparedSource['totalBytes'] = $selection['selectedBytes'];
        $preparedSource['coverage'] = $selection['coverage'];
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

        $progressRenderer = new ModuleReviewProgressRenderer($output);
        $progress = static function (string $event, array $context) use ($progressRenderer): void {
            $progressRenderer->handle($event, $context);
        };
        try {
            $result = $this->analyzeModule(
                $module['resolvedPath'],
                $module['moduleName'],
                $runtime,
                $progress,
                $moduleInfo['name'],
                $preparedSource,
            );
        } finally {
            $progressRenderer->finishOnFailure();
        }

        $pdfPath = null;
        try {
            $pdfPath = $this->writeModuleReviewPdf($result, $module, $moduleInfo);
            $output->writeln('Generating PDF report... done');
        } catch (\Throwable) {
            $output->writeln('<comment>PDF report could not be generated.</comment>');
            $output->writeln('<comment>Review results are still available above.</comment>');
        }
        (new ModuleReviewSummaryRenderer($output))->render($result, $pdfPath);

        if (!(bool) $helper->ask($input, $output, new ConfirmationQuestion(
            'Continue with project publishing? [y/N] ',
            false,
        ))) {
            $output->writeln('Project publishing cancelled.');

            return new ModuleReviewWorkflowResult(
                false,
                $runtime,
                $result,
                $this->sourceCoverage($result),
            );
        }

        return new ModuleReviewWorkflowResult(
            true,
            $runtime,
            $result,
            $this->sourceCoverage($result),
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
        $output->writeln('Selected source is locally filtered/redacted on a best-effort basis');
        $output->writeln('before being sent to this AI provider. Drupal.org search does not receive it.');
        if ($output->isVerbose()) {
            $output->writeln('');
            $output->writeln('<comment>Review details</comment>');
            $output->writeln('<comment>Two AI requests are used: characterization and candidate comparison.</comment>');
            $output->writeln('<comment>The candidate list comes from Drupal.org project search, not from the AI.</comment>');
            $output->writeln('<comment>A PDF report is generated locally from the structured results.</comment>');
        }
    }

    /**
     * @param array{resolvedPath: string, moduleName: string, projectRoot: ?string, infoFile: string, webRoot: ?string} $module
     * @param array{name: string, type: string, coreVersionRequirement: string, dependencies: list<string>} $moduleInfo
     * @param array<string, mixed> $result
     */
    private function writeModuleReviewPdf(array $result, array $module, array $moduleInfo): string
    {
        $targetPath = $this->moduleReviewPdfPath($module);
        if ($this->pdfWriter !== null) {
            return ($this->pdfWriter)($targetPath, $result, [
                'machineName' => $module['moduleName'],
                'name' => $moduleInfo['name'],
                'type' => $moduleInfo['type'],
                'coreVersionRequirement' => $moduleInfo['coreVersionRequirement'],
            ]);
        }

        (new ModuleReviewPdfReportWriter())->write($targetPath, $result, [
            'machineName' => $module['moduleName'],
            'name' => $moduleInfo['name'],
            'type' => $moduleInfo['type'],
            'coreVersionRequirement' => $moduleInfo['coreVersionRequirement'],
        ]);

        return $targetPath;
    }

    /**
     * @param array{resolvedPath: string, moduleName: string, projectRoot: ?string} $module
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
     * @return array<string, mixed>
     */
    private function prepareModuleSource(string $modulePath): array
    {
        if ($this->sourcePreparer !== null) {
            return ($this->sourcePreparer)($modulePath);
        }

        return $this->getAnalyzer()->prepareSource($modulePath);
    }

    /**
     * @param array{type: 'ddev'|'lando'|'local-drush', workingDirectory: string, command: list<string>} $runtime
     * @param array<string, mixed> $preparedSource
     *
     * @return array<string, mixed>
     */
    private function analyzeModule(
        string $modulePath,
        string $machineName,
        array $runtime,
        callable $progress,
        string $humanName,
        array $preparedSource,
    ): array {
        if ($this->moduleAnalyzer !== null) {
            return ($this->moduleAnalyzer)(
                $modulePath,
                $machineName,
                $runtime,
                $progress,
                $humanName,
                $preparedSource,
            );
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
            "Choose source coverage:\n\n"
            . implode("\n", $optionLines)
            . "\n\nSelection [1]: ",
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

    /**
     * @param array<string, mixed> $analysisResult
     */
    private function sourceCoverage(array $analysisResult): string
    {
        $source = $analysisResult['source'] ?? null;
        $coverage = is_array($source) ? $source['coverage'] ?? null : null;

        return is_array($coverage) && ($coverage['mode'] ?? null) === 'selected' ? 'partial' : 'full';
    }
}
