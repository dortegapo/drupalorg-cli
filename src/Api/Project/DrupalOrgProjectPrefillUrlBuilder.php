<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class DrupalOrgProjectPrefillUrlBuilder
{
    public const PROJECT_CREATION_URL = 'https://www.drupal.org/node/add/project-module';

    public const FRAGMENT_PREFIX = 'drupalorg-cli-prefill=';

    public const MAX_URL_LENGTH = 7000;

    public function build(DrupalOrgProjectFormData $formData): string
    {
        $json = json_encode(
            $formData->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $url = self::PROJECT_CREATION_URL . '#' . self::FRAGMENT_PREFIX . $encoded;

        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new \LengthException('Drupal.org project prefill URL exceeds the supported length.');
        }

        return $url;
    }
}
