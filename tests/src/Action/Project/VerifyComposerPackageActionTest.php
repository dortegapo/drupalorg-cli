<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Action\Project;

use mglaman\DrupalOrg\Action\Project\VerifyComposerPackageAction;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Result\Project\ComposerPackageResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerifyComposerPackageAction::class)]
final class VerifyComposerPackageActionTest extends TestCase
{
    public function testMissingComposerIsDistinguished(): void
    {
        $result = (new VerifyComposerPackageAction('/definitely/missing/composer'))(
            'foo',
            ProjectReleaseVersion::fromString('1.0.0'),
        );

        self::assertSame(ComposerPackageResult::COMPOSER_UNAVAILABLE, $result->status);
        self::assertFalse($result->available());
    }

    public function testAvailablePackageIsReportedWithoutNetworkAccess(): void
    {
        $result = $this->runFakeComposer('{"versions":["1.0.0"]}', '', 0);

        self::assertSame(ComposerPackageResult::AVAILABLE, $result->status);
        self::assertTrue($result->available());
    }

    public function testVersionPendingIsDistinctFromPackagePending(): void
    {
        $result = $this->runFakeComposer('{"versions":["1.0.1"]}', '', 0);

        self::assertSame(ComposerPackageResult::PACKAGE_OR_VERSION_PENDING, $result->status);
    }

    public function testMissingPackageIsReportedAsPending(): void
    {
        $result = $this->runFakeComposer('', 'Could not find package drupal/foo', 1);

        self::assertSame(ComposerPackageResult::PACKAGE_OR_VERSION_PENDING, $result->status);
    }

    public function testNetworkFailureIsDistinctFromPackagePending(): void
    {
        $result = $this->runFakeComposer('', 'Could not download repository metadata', 1);

        self::assertSame(ComposerPackageResult::NETWORK_OR_REPOSITORY_FAILURE, $result->status);
    }

    private function runFakeComposer(string $stdout, string $stderr, int $exitCode): ComposerPackageResult
    {
        $directory = sys_get_temp_dir() . '/drupalorg-fake-composer-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $binary = $directory . '/composer';
        $script = "#!/bin/sh\n";
        if ($stdout !== '') {
            $script .= 'printf %s ' . escapeshellarg($stdout) . "\n";
        }
        if ($stderr !== '') {
            $script .= 'printf %s ' . escapeshellarg($stderr) . " >&2\n";
        }
        $script .= 'exit ' . $exitCode . "\n";
        file_put_contents($binary, $script);
        chmod($binary, 0700);
        try {
            return (new VerifyComposerPackageAction($binary))(
                'foo',
                ProjectReleaseVersion::fromString('1.0.0'),
            );
        } finally {
            unlink($binary);
            rmdir($directory);
        }
    }
}
