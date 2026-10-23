<?php

declare(strict_types=1);

use mglaman\DrupalOrg\Auth\DeviceAuthorizationClient;
use mglaman\DrupalOrg\Auth\OAuthConfiguration;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$configuration = new OAuthConfiguration(
    deviceAuthorizationEndpoint: 'http://localhost:8180/realms/drupalorg-cli-dev/protocol/openid-connect/auth/device',
    tokenEndpoint: 'http://localhost:8180/realms/drupalorg-cli-dev/protocol/openid-connect/token',
    clientId: 'drupalorg-cli',
    scopes: [],
);

$client = new DeviceAuthorizationClient($configuration);
$deviceAuthorization = $client->requestDeviceAuthorization();

if (isset($deviceAuthorization['verification_uri_complete'])) {
    echo "Open this URL to authenticate:\n\n";
    echo $deviceAuthorization['verification_uri_complete'] . "\n\n";
} else {
    echo "Open:\n\n";
    echo $deviceAuthorization['verification_uri'] . "\n\n";
    echo "Enter code:\n\n";
    echo $deviceAuthorization['user_code'] . "\n\n";
}

echo "Username: test-user\n";
echo "Password: test-password\n\n";
echo "Waiting for authorization...\n";

$credentials = $client->waitForToken($deviceAuthorization);
unset($credentials);

echo "Device authorization successful.\n";
