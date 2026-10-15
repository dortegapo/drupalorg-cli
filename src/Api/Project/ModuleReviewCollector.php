<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class ModuleReviewCollector
{
    private const MAX_FILE_BYTES = 262144;

    /**
     * @var list<string>
     */
    private const EXCLUDED_DIRECTORIES = [
        '.git',
        'vendor',
        'node_modules',
        'coverage',
        'dist',
        'build',
        '.idea',
        '.vscode',
    ];

    /**
     * @var list<string>
     */
    private const INCLUDED_EXTENSIONS = [
        'php',
        'module',
        'install',
        'inc',
        'yml',
        'yaml',
        'json',
        'md',
        'txt',
        'twig',
        'js',
        'mjs',
        'cjs',
        'jsx',
        'ts',
        'tsx',
        'css',
        'scss',
        'sass',
        'less',
    ];

    /**
     * Collects textual files from a module directory.
     *
     * @return array{
     *     files: list<array{path: string, bytes: int, content: string}>,
     *     skipped: list<array{path: string, reason: string}>,
     *     totalBytes: int
     * }
     */
    public function collect(string $modulePath): array
    {
        if (!is_dir($modulePath)) {
            throw new \RuntimeException('Could not collect module files from the provided path.');
        }

        $resolvedPath = realpath($modulePath);
        if ($resolvedPath === false) {
            throw new \RuntimeException('Could not collect module files from the provided path.');
        }

        $files = [];
        $skipped = [];
        $this->collectDirectory($resolvedPath, '', $files, $skipped);

        usort($files, static fn (array $first, array $second): int => $first['path'] <=> $second['path']);
        usort($skipped, static fn (array $first, array $second): int => $first['path'] <=> $second['path']);

        return [
            'files' => $files,
            'skipped' => $skipped,
            'totalBytes' => array_sum(array_column($files, 'bytes')),
        ];
    }

    /**
     * @param list<array{path: string, bytes: int, content: string}> $files
     * @param list<array{path: string, reason: string}> $skipped
     */
    private function collectDirectory(
        string $directory,
        string $relativeDirectory,
        array &$files,
        array &$skipped
    ): void {
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolutePath = $directory . DIRECTORY_SEPARATOR . $entry;
            $relativePath = $relativeDirectory === ''
                ? $entry
                : $relativeDirectory . '/' . $entry;

            if (is_link($absolutePath)) {
                $skipped[] = [
                    'path' => $relativePath,
                    'reason' => 'symlink',
                ];
                continue;
            }

            if (is_dir($absolutePath)) {
                if (in_array($entry, self::EXCLUDED_DIRECTORIES, true)) {
                    continue;
                }

                $this->collectDirectory($absolutePath, $relativePath, $files, $skipped);
                continue;
            }

            if (is_file($absolutePath)) {
                $this->collectFile($absolutePath, $relativePath, $files, $skipped);
                continue;
            }

            $skipped[] = [
                'path' => $relativePath,
                'reason' => 'unsupported',
            ];
        }
    }

    /**
     * @param list<array{path: string, bytes: int, content: string}> $files
     * @param list<array{path: string, reason: string}> $skipped
     */
    private function collectFile(
        string $absolutePath,
        string $relativePath,
        array &$files,
        array &$skipped
    ): void {
        $filename = basename($absolutePath);

        if ($this->isSensitive($filename)) {
            $skipped[] = ['path' => $relativePath, 'reason' => 'sensitive'];
            return;
        }

        if ($this->isLockfile($filename)) {
            $skipped[] = ['path' => $relativePath, 'reason' => 'lockfile'];
            return;
        }

        if ($this->isMinified($filename)) {
            $skipped[] = ['path' => $relativePath, 'reason' => 'minified'];
            return;
        }

        if (!$this->isSupported($filename)) {
            $skipped[] = ['path' => $relativePath, 'reason' => 'unsupported'];
            return;
        }

        if (!is_readable($absolutePath)) {
            $skipped[] = ['path' => $relativePath, 'reason' => 'unreadable'];
            return;
        }

        $size = filesize($absolutePath);
        if ($size === false) {
            $skipped[] = ['path' => $relativePath, 'reason' => 'unreadable'];
            return;
        }

        if ($size > self::MAX_FILE_BYTES) {
            $skipped[] = ['path' => $relativePath, 'reason' => 'too_large'];
            return;
        }

        $content = file_get_contents($absolutePath, false, null, 0, self::MAX_FILE_BYTES + 1);
        if ($content === false) {
            $skipped[] = ['path' => $relativePath, 'reason' => 'unreadable'];
            return;
        }

        if (strlen($content) > self::MAX_FILE_BYTES) {
            $skipped[] = ['path' => $relativePath, 'reason' => 'too_large'];
            return;
        }

        if (str_contains($content, "\0")) {
            $skipped[] = ['path' => $relativePath, 'reason' => 'binary'];
            return;
        }

        $files[] = [
            'path' => $relativePath,
            'bytes' => strlen($content),
            'content' => $content,
        ];
    }

    private function isSupported(string $filename): bool
    {
        if ($filename === 'README') {
            return true;
        }

        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::INCLUDED_EXTENSIONS, true);
    }

    private function isSensitive(string $filename): bool
    {
        $normalized = strtolower($filename);

        return $normalized === '.env'
            || str_starts_with($normalized, '.env.')
            || $normalized === 'id_rsa'
            || str_starts_with($normalized, 'id_rsa.')
            || in_array($normalized, ['credentials.json', 'auth.json'], true)
            || in_array(pathinfo($normalized, PATHINFO_EXTENSION), ['pem', 'key', 'p12', 'pfx'], true);
    }

    private function isLockfile(string $filename): bool
    {
        return in_array(strtolower($filename), [
            'composer.lock',
            'package-lock.json',
            'pnpm-lock.yaml',
            'yarn.lock',
        ], true);
    }

    private function isMinified(string $filename): bool
    {
        $normalized = strtolower($filename);

        return str_ends_with($normalized, '.min.js') || str_ends_with($normalized, '.min.css');
    }
}
