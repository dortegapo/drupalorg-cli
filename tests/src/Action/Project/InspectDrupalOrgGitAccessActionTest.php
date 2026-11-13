<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Action\Project;

use mglaman\DrupalOrg\Action\Project\InspectDrupalOrgGitAccessAction;
use mglaman\DrupalOrg\Result\Project\DrupalOrgGitAccessResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InspectDrupalOrgGitAccessAction::class)]
#[CoversClass(DrupalOrgGitAccessResult::class)]
final class InspectDrupalOrgGitAccessActionTest extends TestCase
{
    /** @var list<string> */
    private array $scripts = [];

    public function testNormalOpenSshAuthenticationTakesPrecedenceOverExplicitIdentities(): void
    {
        $identity = sys_get_temp_dir() . '/drupalorg-private-key-normal-' . bin2hex(random_bytes(4));
        $marker = sys_get_temp_dir() . '/drupalorg-explicit-identity-used-' . bin2hex(random_bytes(4));
        file_put_contents($identity, 'not a real key');
        $this->scripts[] = $identity;
        $this->scripts[] = $marker;
        $result = (new InspectDrupalOrgGitAccessAction(
            sshBinary: $this->script(
                "if printf '%s\\n' \"\$@\" | grep -Fq -- '-i'; then touch '" . $marker . "'; fi; printf '%s\\n' 'Welcome to GitLab, @normal-user!'; exit 1",
            ),
            configuredIdentityFiles: [$identity],
        ))();

        self::assertSame(DrupalOrgGitAccessResult::AUTHENTICATED, $result->status);
        self::assertSame('normal-user', $result->username);
        self::assertNull($result->identityFile);
        self::assertNull($result->transport());
        self::assertFileDoesNotExist($marker);
    }

    public function testWelcomeMessageAuthenticatesEvenWhenSshExitsNonZero(): void
    {
        $result = (new InspectDrupalOrgGitAccessAction(
            sshBinary: $this->script("printf '%s\\n' 'Welcome to GitLab, @alice!' >&2; exit 1"),
        ))();

        self::assertSame(DrupalOrgGitAccessResult::AUTHENTICATED, $result->status);
        self::assertSame('alice', $result->username);
    }

    public function testUsesConfiguredPrivateIdentityWithoutAnAuthenticationAgent(): void
    {
        $identity = sys_get_temp_dir() . '/drupalorg-private-key-' . bin2hex(random_bytes(4));
        file_put_contents($identity, 'not a real key');
        $this->scripts[] = $identity;
        $result = (new InspectDrupalOrgGitAccessAction(
            sshBinary: $this->script("if printf '%s\\n' \"\$@\" | grep -Fq -- '" . $identity . "'; then printf '%s\\n' 'Welcome to GitLab, @identity-user!'; else printf '%s\\n' 'Permission denied (publickey).' >&2; fi; exit 1"),
            configuredIdentityFiles: [$identity],
        ))();

        self::assertSame(DrupalOrgGitAccessResult::AUTHENTICATED, $result->status);
        self::assertSame('identity-user', $result->username);
        self::assertSame($identity, $result->identityFile);
        self::assertSame(
            'ssh -o BatchMode=yes -o IdentitiesOnly=yes -i ' . escapeshellarg($identity),
            $result->transport()?->environment()['GIT_SSH_COMMAND'],
        );
    }

    public function testSelectsTheIdentityThatActuallyAuthenticates(): void
    {
        $first = sys_get_temp_dir() . '/drupalorg-private-key-first-' . bin2hex(random_bytes(4));
        $second = sys_get_temp_dir() . '/drupalorg-private-key-second-' . bin2hex(random_bytes(4));
        $log = sys_get_temp_dir() . '/drupalorg-ssh-attempts-' . bin2hex(random_bytes(4));
        file_put_contents($first, 'first');
        file_put_contents($second, 'second');
        $this->scripts[] = $first;
        $this->scripts[] = $second;
        $this->scripts[] = $log;
        $result = (new InspectDrupalOrgGitAccessAction(
            sshBinary: $this->script("printf '%s\\n' \"\$*\" >> '" . $log . "'; if printf '%s\\n' \"\$@\" | grep -Fq -- '" . $second . "'; then printf '%s\\n' 'Welcome to GitLab, @second-user!'; else printf '%s\\n' 'Permission denied (publickey).' >&2; fi; exit 1"),
            configuredIdentityFiles: [$first, $second],
        ))();

        self::assertSame(DrupalOrgGitAccessResult::AUTHENTICATED, $result->status);
        self::assertSame($second, $result->identityFile);
        $attempts = file($log, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($attempts);
        self::assertCount(3, $attempts);
        self::assertStringNotContainsString($first, $attempts[0]);
        self::assertStringContainsString($first, $attempts[1]);
        self::assertStringContainsString($second, $attempts[2]);
    }

    public function testPublicKeyFailureIsAuthenticationMissing(): void
    {
        $result = (new InspectDrupalOrgGitAccessAction(
            sshBinary: $this->script("printf '%s\\n' 'Permission denied (publickey).' >&2; exit 255"),
        ))();

        self::assertSame(DrupalOrgGitAccessResult::AUTHENTICATION_MISSING, $result->status);
    }

    public function testHostKeyFailureIsNotAuthenticationMissing(): void
    {
        $result = (new InspectDrupalOrgGitAccessAction(
            sshBinary: $this->script("printf '%s\\n' 'Host key verification failed.' >&2; exit 255"),
        ))();

        self::assertSame(DrupalOrgGitAccessResult::HOST_KEY_FAILURE, $result->status);
    }

    public function testNetworkFailureIsReportedSeparately(): void
    {
        $result = (new InspectDrupalOrgGitAccessAction(
            sshBinary: $this->script("printf '%s\\n' 'Could not resolve hostname git.drupal.org' >&2; exit 255"),
        ))();

        self::assertSame(DrupalOrgGitAccessResult::NETWORK_FAILURE, $result->status);
    }

    public function testProcessTimeoutIsReportedAsNetworkFailure(): void
    {
        $result = (new InspectDrupalOrgGitAccessAction(
            sshBinary: $this->script('sleep 1'),
            timeout: 0.01,
        ))();

        self::assertSame(DrupalOrgGitAccessResult::NETWORK_FAILURE, $result->status);
    }

    public function testUnavailableSshIsReportedWithoutChangingTheMachine(): void
    {
        $result = (new InspectDrupalOrgGitAccessAction(
            sshBinary: sys_get_temp_dir() . '/drupalorg-cli-missing-ssh-' . bin2hex(random_bytes(6)),
        ))();

        self::assertSame(DrupalOrgGitAccessResult::SSH_UNAVAILABLE, $result->status);
    }

    private function script(string $command): string
    {
        $path = tempnam(sys_get_temp_dir(), 'drupalorg-ssh-');
        self::assertIsString($path);
        file_put_contents($path, "#!/bin/sh\n" . $command . "\n");
        chmod($path, 0700);
        $this->scripts[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            if (is_file($script)) {
                unlink($script);
            }
        }
    }
}
