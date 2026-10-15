<?php

declare(strict_types=1);

namespace mglaman\DrupalOrgCli\Command\Auth;

use mglaman\DrupalOrg\Auth\ConfigDirectoryResolver;
use mglaman\DrupalOrg\Auth\DeviceAuthorizationClient;
use mglaman\DrupalOrg\Auth\OAuthConfiguration;
use mglaman\DrupalOrg\Auth\TokenStorage;
use Symfony\Component\Console\Command\Command as ConsoleCommand;

final class AuthCommandFactory
{

    /**
     * @return list<ConsoleCommand>
     */
    public function create(): array
    {
        $oauthConfiguration = new OAuthConfiguration(
            deviceAuthorizationEndpoint: 'http://localhost:8180/realms/drupalorg-cli-dev/protocol/openid-connect/auth/device',
            tokenEndpoint: 'http://localhost:8180/realms/drupalorg-cli-dev/protocol/openid-connect/token',
            clientId: 'drupalorg-cli',
            scopes: [],
        );
        $oauthClient = new DeviceAuthorizationClient($oauthConfiguration);
        $configDirectory = (new ConfigDirectoryResolver())->resolve();
        $tokenStorage = new TokenStorage(
            $configDirectory . DIRECTORY_SEPARATOR . 'keycloak-dev'
        );

        return [
            new Login($oauthClient, $tokenStorage),
            new Status($tokenStorage),
            new Logout($tokenStorage),
        ];
    }
}
