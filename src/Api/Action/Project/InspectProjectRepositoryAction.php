<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Result\Project\ProjectRepositoryResult;
use Symfony\Component\Process\Process;

/** Inspects public Drupal.org refs without cloning a project repository. */
final class InspectProjectRepositoryAction implements ActionInterface
{
    public function __invoke(string $machineName): ProjectRepositoryResult
    {
        $url = 'https://git.drupalcode.org/project/' . $machineName . '.git';
        $process = new Process(['git', 'ls-remote', '--heads', '--tags', $url]);
        $process->setTimeout(20);
        $process->run();
        if (!$process->isSuccessful()) {
            return new ProjectRepositoryResult($url, false, failure: $this->failure($process->getErrorOutput()));
        }

        $branches = [];
        $tags = [];
        $lines = preg_split('/\R/', trim($process->getOutput()));
        foreach ($lines === false ? [] : $lines as $line) {
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            [$sha, $ref] = array_pad($parts === false ? [] : $parts, 2, '');
            if (str_starts_with($ref, 'refs/heads/')) {
                $branches[substr($ref, 11)] = $sha;
            } elseif (str_starts_with($ref, 'refs/tags/')) {
                $tag = substr($ref, 10);
                if (str_ends_with($tag, '^{}')) {
                    $tags[substr($tag, 0, -3)] = $sha;
                } elseif (!isset($tags[$tag])) {
                    $tags[$tag] = $sha;
                }
            }
        }

        return new ProjectRepositoryResult($url, true, $branches, $tags);
    }

    private function failure(string $error): string
    {
        $error = strtolower($error);
        if (str_contains($error, 'authentication') || str_contains($error, 'permission denied')) {
            return 'authentication_failed';
        }
        if (str_contains($error, 'not found') || str_contains($error, 'does not appear')) {
            return 'repository_not_ready';
        }
        return 'network_failure';
    }
}
