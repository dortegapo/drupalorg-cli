<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Result\Project\ProjectLocalSourceResult;
use Symfony\Component\Process\Process;

/** Creates the explicit initial module-only commit when publication needs one. */
class BootstrapProjectSourceAction implements ActionInterface
{
    public function __construct(private readonly ?InspectLocalProjectSourceAction $inspector = null)
    {
    }

    public function __invoke(
        ModuleContext $module,
        ProjectLocalSourceResult $inspection,
        ?string $userName = null,
        ?string $userEmail = null,
    ): ProjectLocalSourceResult {
        if (!$inspection->bootstrapRequired) {
            InspectLocalProjectSourceAction::assertPublishable($inspection);
            return $inspection;
        }
        if ($inspection->stagedChangesOutsideModule) {
            throw new \RuntimeException("The Git index already contains staged changes outside the module.\n\nCommit or unstage them before project:publish creates the initial module commit.");
        }

        $root = $inspection->repositoryExists ? $inspection->gitRoot : $module->location->resolvedPath;
        if (!$inspection->repositoryExists) {
            $this->run($root, ['init']);
        }
        $current = ($this->inspector ?? new InspectLocalProjectSourceAction())($module);
        if (!$current->repositoryExists) {
            throw new \RuntimeException('Could not initialize a Git repository for the module.');
        }
        if ($current->stagedChangesOutsideModule) {
            throw new \RuntimeException("The Git index already contains staged changes outside the module.\n\nCommit or unstage them before project:publish creates the initial module commit.");
        }
        if ($current->candidateFiles === []) {
            throw new \RuntimeException('The module has no non-ignored files to commit.');
        }
        $currentInspector = $this->inspector ?? new InspectLocalProjectSourceAction();
        $currentInspector->validateCandidateFiles($current->gitRoot, $current->candidateFiles);
        [$name, $email] = $this->configureIdentity($current, $userName, $userEmail);
        $path = $current->modulePrefix === '' ? '.' : $current->modulePrefix;
        $this->run($current->gitRoot, ['add', '--', $path]);
        $staged = $this->paths($this->run($current->gitRoot, ['diff', '--cached', '--name-only', '-z'])->getOutput());
        foreach ($staged as $file) {
            if (!$this->isWithinModule($file, $current->modulePrefix)) {
                throw new \RuntimeException('Bootstrap staging included a path outside the module. No commit was created.');
            }
        }
        if ($staged === []) {
            throw new \RuntimeException('The module has no non-ignored files to commit.');
        }
        $this->run($current->gitRoot, [
            '-c', 'core.hooksPath=/dev/null',
            '-c', 'user.name=' . $name,
            '-c', 'user.email=' . $email,
            'commit',
            '-m', 'Initial ' . $module->location->machineName . ' implementation',
        ]);

        return ($this->inspector ?? new InspectLocalProjectSourceAction())($module);
    }

    /** @return array{string, string} */
    private function configureIdentity(
        ProjectLocalSourceResult $inspection,
        ?string $userName,
        ?string $userEmail,
    ): array {
        $name = $userName ?? $inspection->gitUserName;
        $email = $userEmail ?? $inspection->gitUserEmail;
        if ($name === null || $email === null || $name === '' || $email === '') {
            throw new \RuntimeException('Git identity is not configured. Set user.name and user.email before creating the initial module commit.');
        }
        if ($userName !== null || $userEmail !== null) {
            $this->run($inspection->gitRoot, ['config', '--local', 'user.name', $name]);
            $this->run($inspection->gitRoot, ['config', '--local', 'user.email', $email]);
        }

        return [$name, $email];
    }

    private function isWithinModule(string $path, string $prefix): bool
    {
        $prefix = rtrim($prefix, '/');
        return $prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    /** @return list<string> */
    private function paths(string $output): array
    {
        return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
    }

    /** @param list<string> $args */
    private function run(string $cwd, array $args): Process
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(60);
        $process->setEnv(array_merge($_ENV, $_SERVER, ['GIT_TERMINAL_PROMPT' => '0']));
        $process->mustRun();

        return $process;
    }
}
