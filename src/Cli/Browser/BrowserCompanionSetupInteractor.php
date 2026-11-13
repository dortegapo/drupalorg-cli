<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Browser;

use Closure;
use mglaman\DrupalOrg\Browser\BrowserCompanionDistribution;
use mglaman\DrupalOrg\Browser\BrowserCompanionInstallation;
use mglaman\DrupalOrg\Browser\BrowserCompanionManager;
use mglaman\DrupalOrg\Browser\BrowserCompanionSetupStatus;
use mglaman\DrupalOrg\Browser\BrowserFamily;
use mglaman\DrupalOrg\Browser\BrowserPlatformDetector;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class BrowserCompanionSetupInteractor
{
    public function __construct(
        private readonly BrowserCompanionManager $manager,
        private readonly BrowserCompanionDistribution $distribution,
        private readonly BrowserPlatformDetector $platformDetector = new BrowserPlatformDetector(),
    ) {
    }

    /**
     * @param Closure(BrowserFamily, string): bool|null $openUrl
     */
    public function run(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        BrowserLauncher $launcher,
        ?Closure $openUrl = null,
    ): BrowserCompanionSetupStatus {
        if ($this->manager->isConfigured()) {
            return BrowserCompanionSetupStatus::ALREADY_CONFIGURED;
        }

        $families = $this->platformDetector->browserFamilies();
        $published = $this->distribution->publishedFamilies($families);
        if ($published === []) {
            $output->writeln('<comment>Published browser companion distributions are not available yet.</comment>');
            $choice = $this->askChoice(
                $input,
                $output,
                $helper,
                ['I already installed the companion manually', 'Continue with manual form filling'],
                1,
            );
            if ($choice === 2) {
                return BrowserCompanionSetupStatus::MANUAL_SELECTED;
            }

            $family = $this->askBrowserFamily($input, $output, $helper, $families);

            return $this->save($family, BrowserCompanionInstallation::LOCAL);
        }

        $choices = array_map(
            fn(BrowserFamily $family): string => 'Install via ' . $this->distribution->storeName($family),
            $published,
        );
        $choices[] = 'I already installed the companion manually';
        $choices[] = 'Continue with manual form filling';
        $choice = $this->askChoice($input, $output, $helper, $choices, 1);
        if ($choice === count($choices)) {
            return BrowserCompanionSetupStatus::MANUAL_SELECTED;
        }
        if ($choice === count($choices) - 1) {
            $family = $this->askBrowserFamily($input, $output, $helper, $families);

            return $this->save($family, BrowserCompanionInstallation::LOCAL);
        }

        $family = $published[$choice - 1];
        $storeUrl = $this->distribution->storeUrl($family);
        if ($storeUrl === null) {
            return BrowserCompanionSetupStatus::DISTRIBUTION_UNAVAILABLE;
        }
        $opened = $openUrl !== null
            ? $openUrl($family, $storeUrl)
            : $launcher->open($family, $storeUrl);
        if (!$opened) {
            return BrowserCompanionSetupStatus::LAUNCH_FAILED;
        }

        $output->writeln(sprintf(
            'Install the companion from %s, then return to this terminal.',
            $this->distribution->storeName($family),
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
            return BrowserCompanionSetupStatus::MANUAL_SELECTED;
        }

        return $this->save($family, BrowserCompanionInstallation::STORE);
    }

    /**
     * @param list<string> $choices
     */
    private function askChoice(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        array $choices,
        int $default,
    ): int {
        $output->writeln('');
        $output->writeln('<fg=cyan;options=bold>Automatic Drupal.org form filling</>');
        $output->writeln('');
        $output->writeln('Choose setup:');
        foreach ($choices as $index => $choice) {
            $output->writeln(sprintf('  %d. %s', $index + 1, $choice));
        }
        $question = new Question('<fg=yellow;options=bold>Selection:</> ', (string) $default);
        $question->setValidator(static function (mixed $value) use ($choices): int {
            $selection = filter_var(trim((string) $value), FILTER_VALIDATE_INT);
            if ($selection === false || $selection < 1 || $selection > count($choices)) {
                throw new \InvalidArgumentException('Please choose one of the listed options.');
            }

            return $selection;
        });

        return (int) $helper->ask($input, $output, $question);
    }

    /**
     * @param list<BrowserFamily> $families
     */
    private function askBrowserFamily(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        array $families,
    ): BrowserFamily {
        $choices = array_map(fn(BrowserFamily $family): string => $this->distribution->label($family), $families);
        $selection = $this->askChoice($input, $output, $helper, $choices, 1);

        return $families[$selection - 1];
    }

    private function save(
        BrowserFamily $family,
        BrowserCompanionInstallation $installation,
    ): BrowserCompanionSetupStatus {
        try {
            $this->manager->configure($family, $installation);
        } catch (\RuntimeException | \InvalidArgumentException) {
            return BrowserCompanionSetupStatus::SAVE_FAILED;
        }

        return BrowserCompanionSetupStatus::CONFIGURED;
    }
}
