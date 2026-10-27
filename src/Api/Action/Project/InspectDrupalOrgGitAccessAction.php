<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Result\Project\DrupalOrgGitAccessResult;
use Symfony\Component\Process\Process;

/** Performs one bounded, non-interactive Drupal.org Git SSH access check. */
final class InspectDrupalOrgGitAccessAction implements ActionInterface
{
    public const SSH_KEY_SETTINGS_URL = 'https://git.drupalcode.org/-/user_settings/ssh_keys';

    public function __construct(
        private readonly string $host = 'git.drupal.org',
        private readonly string $sshBinary = 'ssh',
        private readonly float $timeout = 10.0,
    ) {
    }

    public function __invoke(): DrupalOrgGitAccessResult
    {
        try {
            $process = new Process([
                $this->sshBinary,
                '-T',
                '-o',
                'BatchMode=yes',
                '-o',
                'ConnectTimeout=' . (string) max(1, (int) $this->timeout),
                'git@' . $this->host,
            ]);
            $process->setTimeout($this->timeout);
            $process->setEnv(array_merge($_ENV, $_SERVER, ['GIT_TERMINAL_PROMPT' => '0']));
            $process->run();
        } catch (\Throwable $exception) {
            return new DrupalOrgGitAccessResult(
                DrupalOrgGitAccessResult::SSH_UNAVAILABLE,
                diagnostic: $exception->getMessage(),
            );
        }

        $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());
        $username = $this->username($output);
        if ($username !== null) {
            return new DrupalOrgGitAccessResult(DrupalOrgGitAccessResult::AUTHENTICATED, $username);
        }
        if ($this->contains($output, '/host key verification failed|offending .*key|remote host identification has changed/i')) {
            return new DrupalOrgGitAccessResult(DrupalOrgGitAccessResult::HOST_KEY_FAILURE, diagnostic: $output);
        }
        if (($process->getExitCode() === null && !$process->isSuccessful())
            || $this->contains($output, '/could not resolve hostname|name or service not known|temporary failure in name resolution|connection timed out|connection refused|network is unreachable|no route to host/i')) {
            return new DrupalOrgGitAccessResult(DrupalOrgGitAccessResult::NETWORK_FAILURE, diagnostic: $output);
        }
        if ($process->getExitCode() === 127 || $this->contains($output, '/command not found|executable file not found|no such file or directory/i')) {
            return new DrupalOrgGitAccessResult(DrupalOrgGitAccessResult::SSH_UNAVAILABLE, diagnostic: $output);
        }
        if ($this->contains($output, '/permission denied \(publickey\)|no supported authentication methods|authentication failed|could not authenticate/i')) {
            return new DrupalOrgGitAccessResult(DrupalOrgGitAccessResult::AUTHENTICATION_MISSING, diagnostic: $output);
        }

        return new DrupalOrgGitAccessResult(DrupalOrgGitAccessResult::UNKNOWN_FAILURE, diagnostic: $output);
    }

    private function username(string $output): ?string
    {
        if (preg_match('/Welcome to GitLab,\s*@?([A-Za-z0-9_.-]+)!/i', $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function contains(string $value, string $pattern): bool
    {
        return preg_match($pattern, $value) === 1;
    }
}
