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
        $externalProvider = (new DrupalAiBridge())->hasExternalProvider();
        if ($runtime === null && !$externalProvider) {
            $output->writeln('Drupal AI review is not available for this project environment.');

            return new ModuleReviewWorkflowResult(true, null, null);
        }

        if ($runtime === null) {
            $runtime = $this->externalProviderRuntime($module['projectRoot']);
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

        $output->writeln('Preparing module source...');
        try {
            $preparedSource = $this->prepareModuleSource($module['resolvedPath']);
        } catch (\RuntimeException) {
            $output->writeln('AI-assisted contribution review could not prepare the module source.');

            return new ModuleReviewWorkflowResult(true, $runtime, null);
        }

        $selector = new ModuleReviewSourceSelector();
        $eligibleBytes = $preparedSource['totalBytes'];
        $output->writeln(sprintf(
            'Eligible module source: %s across %d files.',
            $this->formatKilobytes($eligibleBytes),
            count($preparedSource['files']),
        ));

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
            $output->writeln('Selecting functionally relevant source...');
            $output->writeln(sprintf(
                'Selected: %s across %d files.',
                $this->formatKilobytes($selection['selectedBytes']),
                count($selection['files']),
            ));
            $output->writeln(sprintf(
                'Omitted due to source budget: %d files.',
                count($selection['omitted']),
            ));
        } else {
            $output->writeln('Source mode: Full module.');
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
        $output->writeln('Generating PDF report...');
        try {
            $pdfPath = $this->writeModuleReviewPdf($result, $module, $moduleInfo);
            $output->writeln('PDF report generated.');
        } catch (\Throwable) {
            $output->writeln('<comment>Could not generate the PDF review report.</comment>');
            $output->writeln('The AI review results are still available in the terminal summary.');
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
        $output->writeln('Optional AI-assisted contribution review');
        $output->writeln('');
        $output->writeln('Before publishing, drupalorg-cli can analyze this module to:');
        $output->writeln('  - understand its purpose and reusable capabilities;');
        $output->writeln('  - assess whether it is reusable enough for Drupal.org contribution;');
        $output->writeln('  - identify changes that may be needed before contributing it;');
        $output->writeln('  - search Drupal.org for existing projects with similar functionality;');
        $output->writeln('  - compare those projects with this module for possible functional overlap;');
        $output->writeln('  - generate a PDF report with the findings.');
        $output->writeln('');
        $output->writeln('The review normally uses two AI requests:');
        $output->writeln('  1. Module analysis.');
        $output->writeln('  2. Semantic comparison against Drupal.org candidates.');
        $output->writeln('');
        $output->writeln('Drupal.org project searches are performed directly against Drupal.org.');
        $output->writeln('The AI does not browse Drupal.org or invent the candidate project list.');
        $output->writeln('The module name and generated search queries are sent to Drupal.org public project search.');
        $output->writeln('Module source code is not sent to Drupal.org for this search.');
        $output->writeln('');
        $output->writeln('Only the selected module is analyzed.');
        $output->writeln('Its source code will be sent to the configured Drupal AI provider:');
        $output->writeln(sprintf('  Provider: %s', $aiContext['providerId']));
        $output->writeln(sprintf('  Model: %s', $aiContext['modelId']));
        $output->writeln('');
        $output->writeln('The CLI excludes known sensitive files and performs best-effort local redaction');
        $output->writeln('of common secrets before sending source code. Files from other modules, vendor,');
        $output->writeln('or unrelated project directories are not intentionally included.');
        $output->writeln('');
        $output->writeln('The PDF is generated locally from the structured review results.');
        $output->writeln('Generating the PDF itself does not make an additional AI request.');
        $output->writeln('');
        $output->writeln('The analysis may consume tokens from your configured AI provider.');
        $output->writeln('The review is advisory only. Its result will not prevent you from continuing with project publishing.');
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
     * Creates a runtime-shaped context for providers that run outside Drupal AI.
     * The command is never used by DrupalAiBridge, but keeps the analyzer contract
     * intact and allows metadata generation to continue without DDEV, Lando, or Drush.
     *
     * @return array{type: 'ddev'|'lando'|'local-drush', workingDirectory: string, command: list<string>}
     */
    private function externalProviderRuntime(?string $projectRoot): array
    {
        $workingDirectory = $projectRoot !== null && is_dir($projectRoot)
            ? $projectRoot
            : (getcwd() ?: sys_get_temp_dir());

        return [
            'type' => 'local-drush',
            'workingDirectory' => $workingDirectory,
            'command' => ['drupalorg-external-ai'],
        ];
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
                ? sprintf('%d KiB — Recommended', $kilobytes)
                : sprintf('%d KiB', $kilobytes);
            $description = $choiceNumber === 1
                ? 'Analyze a relevance-selected subset of the module. Lowest token usage.'
                : 'Analyze a larger relevance-selected subset. Higher coverage and token usage.';
            $optionLines[] = sprintf('  %s. %s', $choice, $label);
            $optionLines[] = '     ' . $description;
            $choiceValues[$choice] = $budget;
            ++$choiceNumber;
        }

        $fullChoice = (string) $choiceNumber;
        $optionLines[] = sprintf('  %s. Full module — %s', $fullChoice, $this->formatKilobytes($eligibleBytes));
        $optionLines[] = '     Send all eligible module source.';
        $optionLines[] = '     Significantly higher token usage and may exceed the configured model\'s context limit.';
        $optionLines[] = '     Sending the complete module may consume substantially more tokens and may exceed the configured model\'s context window.';
        $choiceValues[$fullChoice] = null;
        ++$choiceNumber;

        $cancelChoice = (string) $choiceNumber;
        $optionLines[] = sprintf('  %s. Cancel AI review', $cancelChoice);
        $choiceValues[$cancelChoice] = false;

        $question = new Question(
            "Choose how much source to send to the configured AI provider:\n\n"
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
