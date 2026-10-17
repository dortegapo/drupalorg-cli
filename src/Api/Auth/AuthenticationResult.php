<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Auth;

final class AuthenticationResult
{
    public readonly string $accessToken;

    /**
     * @var 'stored'|'refreshed'|'device'
     */
    public readonly string $method;

    /**
     * @param 'stored'|'refreshed'|'device' $method
     */
    public function __construct(string $accessToken, string $method)
    {
        if (trim($accessToken) === '') {
            throw new \InvalidArgumentException('accessToken must not be empty.');
        }

        if (!in_array($method, ['stored', 'refreshed', 'device'], true)) {
            throw new \InvalidArgumentException('method must be stored, refreshed, or device.');
        }

        $this->accessToken = $accessToken;
        $this->method = $method;
    }
}
