<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Result\Project\ComposerPackageResult;
use Symfony\Component\Process\Process;

/** Checks packages.drupal.org in an isolated Composer directory. */
final class VerifyComposerPackageAction implements ActionInterface
{
    public function __invoke(string $machineName, ProjectReleaseVersion $version): ComposerPackageResult
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drupalorg-composer-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create temporary Composer verification directory.');
        }
        $package = 'drupal/' . $machineName;
        try {
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
                'name' => 'drupalorg-cli/release-verification',
                'repositories' => [['type' => 'composer', 'url' => 'https://packages.drupal.org/8']],
                'config' => ['secure-http' => true],
            ], JSON_THROW_ON_ERROR));
            $process = new Process(['composer', 'show', $package, '--all', '--format=json', '--no-interaction', '--no-plugins'], $directory);
            $process->setTimeout(45);
            $process->run();
            if (!$process->isSuccessful()) {
                return new ComposerPackageResult($package, $version->value, false);
            }
            $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $versions = $data['versions'] ?? [];
            return new ComposerPackageResult($package, $version->value, in_array($version->value, $versions, true));
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Composer returned an invalid package response.', 0, $exception);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || !str_starts_with(basename($directory), 'drupalorg-composer-')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
