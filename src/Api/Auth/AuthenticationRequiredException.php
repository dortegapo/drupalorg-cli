<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Auth;

final class AuthenticationRequiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Interactive authentication is required.');
    }
}
