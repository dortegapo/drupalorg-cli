<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Result\Project\DrupalOrgGitAccessResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** Performs one bounded, non-interactive Drupal.org Git SSH access check. */
final class InspectDrupalOrgGitAccessAction implements ActionInterface
{
    public const SSH_KEY_SETTINGS_URL = 'https://git.drupalcode.org/-/user_settings/ssh_keys';

    public function __construct(
        private readonly string $host = 'git.drupal.org',
        private readonly string $sshBinary = 'ssh',
        private readonly float $timeout = 10.0,
        /** @var list<string>|null $configuredIdentityFiles */
        private readonly ?array $configuredIdentityFiles = null,
    ) {
    }

    public function __invoke(): DrupalOrgGitAccessResult
    {
        // The user's normal OpenSSH configuration, agent, and known_hosts
        // policy must win before we consider any explicit local identities.
        $normal = $this->inspect(null);
        if ($normal->authenticated()) {
            return $normal;
        }
        if (!in_array($normal->status, [
            DrupalOrgGitAccessResult::AUTHENTICATION_MISSING,
            DrupalOrgGitAccessResult::UNKNOWN_FAILURE,
        ], true)) {
            return $normal;
        }

        $identityFiles = $this->identityFiles();
        if ($identityFiles === []) {
            return $normal;
        }

        $last = $normal;
        foreach ($identityFiles as $identityFile) {
            $result = $this->inspect($identityFile);
            if ($result->authenticated()) {
                return new DrupalOrgGitAccessResult(
                    $result->status,
                    $result->username,
                    $result->diagnostic,
                    $identityFile,
                );
            }
            $last = $result;
            if (!in_array($result->status, [
                DrupalOrgGitAccessResult::AUTHENTICATION_MISSING,
                DrupalOrgGitAccessResult::UNKNOWN_FAILURE,
            ], true)) {
                return $result;
            }
        }

        return $last;
    }

    private function inspect(?string $identityFile): DrupalOrgGitAccessResult
    {
        try {
            $command = [
                $this->sshBinary,
                '-T',
                '-o',
                'BatchMode=yes',
            ];
            if ($identityFile !== null) {
                $command[] = '-o';
                $command[] = 'IdentitiesOnly=yes';
                $command[] = '-i';
                $command[] = $identityFile;
            }
            $command[] = '-o';
            $command[] = 'ConnectTimeout=' . (string) max(1, (int) $this->timeout);
            $command[] = 'git@' . $this->host;
            $process = new Process($command);
            $process->setTimeout($this->timeout);
            $process->setEnv(array_merge($_ENV, $_SERVER, ['GIT_TERMINAL_PROMPT' => '0']));
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            return new DrupalOrgGitAccessResult(
                DrupalOrgGitAccessResult::NETWORK_FAILURE,
                diagnostic: $exception->getMessage(),
            );
        } catch (\Throwable $exception) {
            return new DrupalOrgGitAccessResult(
                DrupalOrgGitAccessResult::SSH_UNAVAILABLE,
                diagnostic: $exception->getMessage(),
            );
        }

        $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());
        $username = $this->username($output);
        if ($username !== null) {
            return new DrupalOrgGitAccessResult(DrupalOrgGitAccessResult::AUTHENTICATED, $username, identityFile: $identityFile);
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

    /** @return list<string> */
    private function identityFiles(): array
    {
        if ($this->configuredIdentityFiles !== null) {
            return array_values(array_filter(
                $this->configuredIdentityFiles,
                static fn(string $identityFile): bool => is_file($identityFile) && is_readable($identityFile),
            ));
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (!is_string($home) || $home === '') {
            return [];
        }
        $files = glob($home . DIRECTORY_SEPARATOR . '.ssh' . DIRECTORY_SEPARATOR . 'id_*');
        if ($files === false) {
            return [];
        }

        return array_values(array_filter(
            $files,
            static fn(string $file): bool => is_file($file)
                && is_readable($file)
                && !str_ends_with($file, '.pub'),
        ));
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
