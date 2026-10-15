<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ModuleInfoReader
{

    /**
     * @return array{
     *   name: string,
     *   type: string,
     *   coreVersionRequirement: string
     * }
     */
    public function read(string $infoFile, string $moduleName): array
    {
        try {
            $metadata = Yaml::parseFile($infoFile);
        } catch (ParseException $exception) {
            throw new \RuntimeException(
                sprintf('Could not parse module info file: %s', $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_array($metadata)) {
            throw new \RuntimeException('Module info file must contain a YAML mapping.');
        }

        $infoFileName = $moduleName . '.info.yml';
        if (!array_key_exists('name', $metadata)) {
            throw new \RuntimeException(sprintf('Missing required "name" in %s.', $infoFileName));
        }

        if (!is_string($metadata['name']) || trim($metadata['name']) === '') {
            throw new \RuntimeException(sprintf(
                'Required "name" in %s must be a non-empty string.',
                $infoFileName
            ));
        }

        if (!array_key_exists('type', $metadata)) {
            throw new \RuntimeException(sprintf('Missing required "type" in %s.', $infoFileName));
        }

        $type = $metadata['type'];
        if (!is_string($type) || $type !== 'module') {
            $actualType = is_scalar($type) ? (string) $type : get_debug_type($type);
            throw new \RuntimeException(sprintf(
                'Expected Drupal project type "module", got "%s".',
                $actualType
            ));
        }

        if (!array_key_exists('core_version_requirement', $metadata)) {
            throw new \RuntimeException(sprintf(
                'Missing required "core_version_requirement" in %s.',
                $infoFileName
            ));
        }

        if (!is_string($metadata['core_version_requirement'])
            || trim($metadata['core_version_requirement']) === '') {
            throw new \RuntimeException(sprintf(
                'Required "core_version_requirement" in %s must be a non-empty string.',
                $infoFileName
            ));
        }

        return [
            'name' => $metadata['name'],
            'type' => $type,
            'coreVersionRequirement' => $metadata['core_version_requirement'],
        ];
    }
}
