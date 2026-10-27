<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ModuleContext;
use mglaman\DrupalOrg\Result\Project\ProjectLocalSourceResult;
use Symfony\Component\Process\Process;

/** Validates the committed, clean local module source used for publication. */
class InspectLocalProjectSourceAction implements ActionInterface
{
    public function __invoke(ModuleContext $module): ProjectLocalSourceResult
    {
        $rootProcess = $this->run($module->location->resolvedPath, ['rev-parse', '--show-toplevel'], false);
        if (!$rootProcess->isSuccessful()) {
            throw new \RuntimeException("Module source is not inside a Git repository.\n\nproject:publish only publishes committed Git source.");
        }
        $root = trim($rootProcess->getOutput());
        $prefix = trim($this->run($module->location->resolvedPath, ['rev-parse', '--show-prefix'])->getOutput());
        $head = $this->run($root, ['rev-parse', '--verify', 'HEAD'], false);
        if (!$head->isSuccessful()) {
            throw new \RuntimeException("Module source has no Git commit yet.\n\nproject:publish only publishes committed source.\n\nCommit the module before publishing, then run project:publish again.");
        }
        $commit = trim($head->getOutput());
        $treeRef = $prefix === '' ? 'HEAD^{tree}' : 'HEAD:' . rtrim($prefix, '/');
        $tree = $this->run($root, ['rev-parse', '--verify', $treeRef], false);
        if (!$tree->isSuccessful()) {
            throw new \RuntimeException("Module source is not committed in HEAD.\n\nCommit the module before publishing.");
        }
        $statusPath = $prefix === '' ? '.' : $prefix;
        $status = $this->run($root, ['status', '--porcelain', '--', $statusPath]);
        if (!$status->isSuccessful()) {
            throw new \RuntimeException('Could not inspect local Git source: ' . trim($status->getErrorOutput()));
        }
        if (trim($status->getOutput()) !== '') {
            throw new \RuntimeException("Module source contains uncommitted changes.\n\nCommit or discard them before publishing.\n" . $status->getOutput());
        }
        $this->assertSafeFiles($root, $statusPath);
        $this->assertComposer($module);

        return new ProjectLocalSourceResult($root, $prefix, $commit, trim($tree->getOutput()));
    }

    private function assertSafeFiles(string $root, string $path): void
    {
        $files = array_filter(explode("\0", trim($this->run($root, ['ls-files', '-z', '--', $path])->getOutput())));
        foreach ($files as $file) {
            $name = basename($file);
            if (preg_match('/^(?:\.env(?:\..*)?|auth\.json|id_rsa|id_ed25519|credentials.*|secrets.*|.*\.(?:pem|key))$/i', $name) === 1) {
                throw new \RuntimeException("Potentially sensitive tracked file:\n  " . $file . "\n\nRemove it from the module repository before publishing.");
            }
            $contents = @file_get_contents($root . DIRECTORY_SEPARATOR . $file);
            if ($contents !== false && preg_match('/-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----/', $contents) === 1) {
                throw new \RuntimeException("Potential private key content in tracked file:\n  " . $file . "\n\nRemove it from the module repository before publishing.");
            }
        }
    }

    private function assertComposer(ModuleContext $module): void
    {
        $path = $module->location->resolvedPath . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($path)) {
            return;
        }
        try {
            $composer = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Module composer.json is not valid JSON.', 0, $exception);
        }
        if (!is_array($composer)
            || ($composer['name'] ?? null) !== 'drupal/' . $module->location->machineName
            || ($composer['type'] ?? null) !== 'drupal-module') {
            throw new \RuntimeException(sprintf(
                'Module composer.json must declare name "drupal/%s" and type "drupal-module" before publishing.',
                $module->location->machineName,
            ));
        }
    }

    /** @param list<string> $args */
    private function run(string $cwd, array $args, bool $throw = true): Process
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(60);
        $process->setEnv(array_merge($_ENV, $_SERVER, ['GIT_TERMINAL_PROMPT' => '0']));
        $process->run();
        if ($throw && !$process->isSuccessful()) {
            throw new \RuntimeException('Could not inspect local Git source: ' . trim($process->getErrorOutput()));
        }
        return $process;
    }
}
