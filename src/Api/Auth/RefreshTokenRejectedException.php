<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Auth;

final class RefreshTokenRejectedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The stored refresh token was rejected.');
    }
}
