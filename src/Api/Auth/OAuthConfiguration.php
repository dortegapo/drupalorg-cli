<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Auth;

final class OAuthConfiguration
{

    /**
     * @var list<string>
     */
    public readonly array $scopes;

    /**
     * @param list<mixed> $scopes
     */
    public function __construct(
        public readonly string $deviceAuthorizationEndpoint,
        public readonly string $tokenEndpoint,
        public readonly string $clientId,
        array $scopes,
    ) {
        foreach ([
            'deviceAuthorizationEndpoint' => $this->deviceAuthorizationEndpoint,
            'tokenEndpoint' => $this->tokenEndpoint,
            'clientId' => $this->clientId,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException(sprintf('%s must not be empty.', $name));
            }
        }

        if (!array_is_list($scopes)) {
            throw new \InvalidArgumentException('scopes must be a list.');
        }

        foreach ($scopes as $scope) {
            if (!is_string($scope) || trim($scope) === '') {
                throw new \InvalidArgumentException('scopes must contain non-empty strings.');
            }
        }

        /** @var list<string> $scopes */
        $this->scopes = $scopes;
    }
}
