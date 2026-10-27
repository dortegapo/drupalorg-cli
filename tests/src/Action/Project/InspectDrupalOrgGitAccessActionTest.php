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

    public function testWelcomeMessageAuthenticatesEvenWhenSshExitsNonZero(): void
    {
        $result = (new InspectDrupalOrgGitAccessAction(
            sshBinary: $this->script("printf '%s\\n' 'Welcome to GitLab, @alice!' >&2; exit 1"),
        ))();

        self::assertSame(DrupalOrgGitAccessResult::AUTHENTICATED, $result->status);
        self::assertSame('alice', $result->username);
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
