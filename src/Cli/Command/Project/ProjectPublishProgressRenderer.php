<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Project;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ProjectPublishProgressRenderer
{
    public const PREPARING_MODULE = 'preparing_module';

    public const CHECKING_PROJECT = 'checking_project';

    public const CHECKING_LOCAL_SOURCE = 'checking_local_source';

    public const CHECKING_REPOSITORY = 'checking_repository';

    public const CHECKING_GIT_ACCESS = 'checking_git_access';

    public const PUBLISHING_SOURCE = 'publishing_source';

    public const CREATING_TAG = 'creating_tag';

    public const PREPARING_RELEASE = 'preparing_release';

    public const VERIFYING_COMPOSER = 'verifying_composer';

    public const PREPARING_SOURCE = 'preparing_module_source';

    public const ANALYZING_MODULE = 'analyzing_module';

    public const SEARCHING_DRUPAL_ORG = 'searching_drupal_org';

    public const COMPARING_PROJECTS = 'comparing_similar_projects';

    public const CHECKING_COMPATIBILITY = 'checking_compatibility';

    public const GENERATING_REVIEW_REPORT = 'generating_review_report';

    public const GENERATING_METADATA = 'generating_project_metadata';

    public const PREPARING_PROJECT_FORM = 'preparing_project_form';

    public const VERIFYING_PROJECT = 'verifying_project';

    /**
     * @var array<string, array{label: string, state: 'pending'|'active'|'completed'|'failed'}>
     */
    private array $phases = [];

    private ?string $activePhase = null;

    private bool $suspended = false;

    private readonly ?ConsoleSectionOutput $section;

    public function __construct(
        private readonly OutputInterface $output,
    ) {
        $this->section = $output instanceof ConsoleOutputInterface && $output->isDecorated()
            ? $output->section()
            : null;
    }

    /**
     * @param array<string, string> $phases
     */
    public function register(array $phases): void
    {
        foreach ($phases as $id => $label) {
            if (!isset($this->phases[$id])) {
                $this->phases[$id] = [
                    'label' => $label,
                    'state' => 'pending',
                ];
            }
        }

        if (!$this->suspended) {
            $this->render();
        }
    }

    public function registerReviewPhases(): void
    {
        $this->register([
            self::PREPARING_SOURCE => 'Preparing module source',
            self::ANALYZING_MODULE => 'Analyzing module',
            self::SEARCHING_DRUPAL_ORG => 'Searching Drupal.org',
            self::COMPARING_PROJECTS => 'Comparing similar projects',
            self::CHECKING_COMPATIBILITY => 'Checking compatibility',
            self::GENERATING_REVIEW_REPORT => 'Generating review report',
        ]);
    }

    public function start(string $id, ?string $label = null): void
    {
        if (!isset($this->phases[$id])) {
            if ($label === null) {
                throw new \InvalidArgumentException(sprintf('Unknown project publish progress phase: %s.', $id));
            }
            $this->register([$id => $label]);
        }

        if ($this->activePhase !== null && $this->activePhase !== $id) {
            throw new \LogicException('Only one project publish progress phase can be active.');
        }

        if (in_array($this->phases[$id]['state'], ['completed', 'failed'], true)) {
            return;
        }

        $this->activePhase = $id;
        $this->suspended = false;
        $this->phases[$id]['state'] = 'active';
        $this->render($id);
    }

    public function tick(): void
    {
        // Heartbeats keep the callback contract alive without repeatedly
        // clearing and redrawing the whole console section.
    }

    public function complete(string $id): void
    {
        if (!isset($this->phases[$id])) {
            return;
        }

        if (in_array($this->phases[$id]['state'], ['completed', 'failed'], true)) {
            return;
        }

        $this->phases[$id]['state'] = 'completed';
        if ($this->activePhase === $id) {
            $this->activePhase = null;
        }
        $this->suspended = false;
        $this->render($id);
    }

    public function fail(string $id): void
    {
        if (!isset($this->phases[$id])) {
            return;
        }

        if (in_array($this->phases[$id]['state'], ['completed', 'failed'], true)) {
            return;
        }

        $this->phases[$id]['state'] = 'failed';
        if ($this->activePhase === $id) {
            $this->activePhase = null;
        }
        $this->suspended = false;
        $this->render($id);
    }

    public function failActive(): void
    {
        if ($this->activePhase !== null) {
            $this->fail($this->activePhase);
        }
    }

    public function suspend(): void
    {
        if ($this->section !== null && !$this->suspended) {
            $this->section->clear();
        }
        $this->suspended = true;
    }

    public function resume(): void
    {
        if (!$this->suspended) {
            return;
        }

        $this->suspended = false;
        $this->render();
    }

    public function detail(string $message): void
    {
        if (!$this->output->isVerbose()) {
            return;
        }

        $this->writeOutsideProgress('<comment>' . $message . '</comment>');
    }

    public function warning(string $message): void
    {
        $this->writeOutsideProgress('<comment>' . $message . '</comment>');
    }

    public function finish(): void
    {
        $this->failActive();
    }

    /**
     * Returns a callback suitable for DrupalAiBridge's wait hook.
     */
    public function tickCallback(): callable
    {
        return function (): void {
            $this->tick();
        };
    }

    /**
     * @param string|null $eventPhase
     */
    private function render(?string $eventPhase = null): void
    {
        if ($this->section !== null) {
            $this->section->overwrite($this->lines());
            return;
        }

        if ($eventPhase === null || !isset($this->phases[$eventPhase])) {
            return;
        }

        $phase = $this->phases[$eventPhase];
        $marker = match ($phase['state']) {
            'completed' => '✓',
            'failed' => '✗',
            'active' => '■',
            default => '□',
        };
        $suffix = $phase['state'] === 'active' ? '...' : '';
        $this->output->writeln(sprintf('%s %s%s', $marker, $phase['label'], $suffix));
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $lines = [];
        foreach ($this->phases as $phase) {
            $marker = match ($phase['state']) {
                'completed' => '✓',
                'failed' => '✗',
                'active' => '■',
                default => '□',
            };
            $suffix = $phase['state'] === 'active' && !$this->suspended ? '...' : '';
            $lines[] = sprintf('%s %s%s', $marker, $phase['label'], $suffix);
        }

        return $lines;
    }

    private function writeOutsideProgress(string $message): void
    {
        $this->suspend();
        $this->output->writeln($message);
        $this->resume();
    }
}
