<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Result\Project\ProjectRepositoryResult;
use mglaman\DrupalOrg\Project\ProjectRepositoryUrls;
use Symfony\Component\Process\Process;

/** Inspects public Drupal.org refs without cloning a project repository. */
class InspectProjectRepositoryAction implements ActionInterface
{
    public function __construct(private readonly ?ProjectRepositoryUrls $urls = null)
    {
    }

    public function __invoke(string $machineName): ProjectRepositoryResult
    {
        $url = ($this->urls ?? ProjectRepositoryUrls::forDrupalOrg($machineName))->readUrl;
        try {
            $process = new Process(['git', 'ls-remote', '--heads', '--tags', $url]);
            $process->setTimeout(20);
            $process->setEnv(array_merge($_ENV, $_SERVER, ['GIT_TERMINAL_PROMPT' => '0']));
            $process->run();
        } catch (\Throwable $exception) {
            return new ProjectRepositoryResult($url, false, failure: 'git_not_available');
        }
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
        return self::classifyFailure($error);
    }

    public static function classifyFailure(string $error): string
    {
        $error = strtolower($error);
        if (str_contains($error, 'authentication') || str_contains($error, 'permission denied')) {
            return 'authentication_failed';
        }
        if (str_contains($error, 'git:') && str_contains($error, 'not found')) {
            return 'git_not_available';
        }
        if (preg_match('/repository\b.*\bnot found|project\b.*\bcould not be found|http\s*404/i', $error) === 1
            || str_contains($error, 'does not appear')) {
            return 'repository_not_ready';
        }
        return 'network_failure';
    }
}
