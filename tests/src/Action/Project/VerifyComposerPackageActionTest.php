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
}
