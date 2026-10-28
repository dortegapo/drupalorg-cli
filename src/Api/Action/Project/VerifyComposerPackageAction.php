<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Action\Project;

use mglaman\DrupalOrg\Action\ActionInterface;
use mglaman\DrupalOrg\Project\ProjectReleaseVersion;
use mglaman\DrupalOrg\Result\Project\ComposerPackageResult;
use Symfony\Component\Process\Process;

/** Checks only packages.drupal.org in an isolated Composer directory. */
class VerifyComposerPackageAction implements ActionInterface
{
    public function __construct(private readonly string $composerBinary = 'composer')
    {
    }

    public function __invoke(string $machineName, ProjectReleaseVersion $version): ComposerPackageResult
    {
        $directory = $this->temporaryDirectory();
        $package = 'drupal/' . $machineName;
        try {
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
                'name' => 'drupalorg-cli/release-verification',
                'repositories' => [
                    ['packagist.org' => false],
                    ['type' => 'composer', 'url' => 'https://packages.drupal.org/8'],
                ],
                'config' => ['secure-http' => true],
            ], JSON_THROW_ON_ERROR));
            try {
                $process = new Process([$this->composerBinary, 'show', $package, '--all', '--format=json', '--no-interaction', '--no-plugins'], $directory);
                $process->setTimeout(45);
                $process->setEnv(array_merge($_ENV, $_SERVER, ['COMPOSER_HOME' => $directory . '/home']));
                $process->run();
            } catch (\Throwable) {
                return new ComposerPackageResult($package, $version->value, ComposerPackageResult::COMPOSER_UNAVAILABLE);
            }
            $output = $process->getOutput() . $process->getErrorOutput();
            if (!$process->isSuccessful()) {
                if (preg_match('/Could not find package|does not exist|No package found/i', $output) === 1) {
                    return new ComposerPackageResult($package, $version->value, ComposerPackageResult::PACKAGE_OR_VERSION_PENDING);
                }
                if (preg_match('/Could not|curl|network|repository|SSL|timeout/i', $output) === 1) {
                    return new ComposerPackageResult($package, $version->value, ComposerPackageResult::NETWORK_OR_REPOSITORY_FAILURE);
                }
                return new ComposerPackageResult($package, $version->value, ComposerPackageResult::COMPOSER_UNAVAILABLE);
            }
            try {
                $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException('Composer returned an invalid package response.', 0, $exception);
            }
            $versions = $data['versions'] ?? [];
            return new ComposerPackageResult(
                $package,
                $version->value,
                in_array($version->value, $versions, true)
                    ? ComposerPackageResult::AVAILABLE
                    : ComposerPackageResult::PACKAGE_OR_VERSION_PENDING,
            );
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drupalorg-composer-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create temporary Composer verification directory.');
        }
        return $directory;
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
