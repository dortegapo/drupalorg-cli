<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Browser;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class BrowserCompanionManager
{
    public function __construct(
        private readonly BrowserCompanionConfigStore $configStore = new BrowserCompanionConfigStore(),
        private readonly BrowserCompanionDistribution $distribution = new BrowserCompanionDistribution(),
        private readonly BrowserPlatformDetector $platform = new BrowserPlatformDetector(),
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->getBrowserFamily() !== null;
    }

    public function getBrowserFamily(): ?string
    {
        $browserFamily = $this->configStore->getBrowserFamily();

        return $browserFamily !== null && $this->distribution->isKnown($browserFamily)
            ? $browserFamily
            : null;
    }

    public function supportsAutomaticPrefill(): bool
    {
        $browserFamily = $this->getBrowserFamily();

        return $browserFamily !== null && $this->distribution->supportsAutomaticPrefill($browserFamily);
    }

    public function reset(): void
    {
        $this->configStore->reset();
    }

    public function setup(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        callable $openUrl,
    ): bool {
        $browserFamilies = $this->platform->browserFamilies();
        $choices = array_map(
            fn (string $browserFamily): string => $this->distribution->label($browserFamily),
            $browserFamilies,
        );

        $output->writeln('');
        $output->writeln('<fg=cyan;options=bold>Automatic Drupal.org form filling</>');
        $output->writeln('');
        $output->writeln('Choose your browser:');
        foreach ($choices as $index => $choice) {
            $output->writeln(sprintf('  %d. %s', $index + 1, $choice));
        }
        $question = new Question('<fg=yellow;options=bold>Selection:</> ', '1');
        $question->setValidator(static function (mixed $value) use ($choices): int {
            $value = trim((string) $value);
            $selection = filter_var($value, FILTER_VALIDATE_INT);
            if ($selection === false || $selection < 1 || $selection > count($choices)) {
                throw new \InvalidArgumentException('Please choose one of the listed browsers.');
            }

            return $selection;
        });
        $selection = $helper->ask($input, $output, $question);
        $browserFamily = $browserFamilies[$selection - 1] ?? BrowserCompanionDistribution::MANUAL;

        if ($browserFamily === BrowserCompanionDistribution::MANUAL) {
            $this->writeManualFallback($output);
            return false;
        }

        $storeUrl = $this->distribution->storeUrl($browserFamily);
        if ($storeUrl === null) {
            if ($browserFamily === BrowserCompanionDistribution::SAFARI) {
                $output->writeln('<comment>Safari companion is not published yet. Continuing with manual filling.</comment>');
            } else {
                $output->writeln('<comment>Automatic form filling is not available for this browser.</comment>');
                $output->writeln('<comment>Continuing with manual Drupal.org project creation.</comment>');
            }
            return false;
        }

        $output->writeln('Opening browser companion installation page...');
        if ($openUrl($storeUrl) === false) {
            $this->writeManualFallback($output);
            return false;
        }
        $output->writeln(sprintf(
            'Install the companion from %s, then return to this terminal.',
            $this->distribution->storeName($browserFamily),
        ));
        $confirmation = new Question(
            '<fg=yellow;options=bold>Press Enter when installed · type "manual" to continue without it:</> ',
            '',
        );
        $confirmation->setValidator(static function (mixed $value): string {
            $value = strtolower(trim((string) $value));
            if ($value !== '' && $value !== 'manual') {
                throw new \InvalidArgumentException('Type "manual" or press Enter to continue.');
            }

            return $value;
        });
        if ($helper->ask($input, $output, $confirmation) === 'manual') {
            $this->writeManualFallback($output);
            return false;
        }

        try {
            $this->configStore->saveBrowserFamily($browserFamily);
        } catch (\RuntimeException) {
            $this->writeManualFallback($output);
            return false;
        }
        $output->writeln('<info>✓ Browser companion configured.</info>');

        return true;
    }

    private function writeManualFallback(OutputInterface $output): void
    {
        $output->writeln('<comment>Automatic form filling is unavailable.</comment>');
        $output->writeln('<comment>Continuing with manual Drupal.org project creation.</comment>');
    }
}
