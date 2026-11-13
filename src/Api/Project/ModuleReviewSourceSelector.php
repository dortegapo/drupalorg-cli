<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class ModuleReviewSourceSelector
{
    public const DEFAULT_BUDGET_BYTES = 131072;

    public const AVAILABLE_BUDGETS = [
        self::DEFAULT_BUDGET_BYTES,
        262144,
        524288,
    ];

    /**
     * Select complete source files for one AI #1 request.
     *
     * A null budget means that the complete eligible source should be kept.
     *
     * @param list<array{path: string, bytes: int, content: string}> $files
     *
     * @return array{
     *     files: list<array{path: string, bytes: int, content: string}>,
     *     omitted: list<array{path: string, bytes: int, category: string}>,
     *     selectedBytes: int,
     *     omittedBytes: int,
     *     coverage: array{
     *         mode: 'full'|'selected',
     *         requestedBudgetBytes: ?int,
     *         eligibleFiles: int,
     *         eligibleBytes: int,
     *         selectedFiles: int,
     *         selectedBytes: int,
     *         omittedFiles: int,
     *         omittedBytes: int,
     *         omittedBreakdown: array<string, int>
     *     }
     * }
     */
    public function select(array $files, ?int $budgetBytes = null): array
    {
        $this->validateFiles($files);
        if ($budgetBytes !== null && $budgetBytes <= 0) {
            throw new \InvalidArgumentException('Module review source budget must be positive.');
        }

        $eligibleBytes = array_sum(array_map(
            static fn (array $file): int => strlen($file['content']),
            $files,
        ));
        if ($budgetBytes === null || $budgetBytes >= $eligibleBytes) {
            return $this->result($files, [], null, $eligibleBytes, $eligibleBytes);
        }

        $selectedPaths = [];
        $remaining = $budgetBytes;
        foreach ($this->orderedFiles($files) as $file) {
            $bytes = strlen($file['content']);
            if ($bytes > $remaining) {
                continue;
            }

            $selectedPaths[$file['path']] = true;
            $remaining -= $bytes;
        }

        $selected = [];
        $omitted = [];
        foreach ($files as $file) {
            if (isset($selectedPaths[$file['path']])) {
                $selected[] = $file;
                continue;
            }

            $omitted[] = [
                'path' => $file['path'],
                'bytes' => strlen($file['content']),
                'category' => $this->category($file['path']),
            ];
        }

        $selectedBytes = array_sum(array_map(
            static fn (array $file): int => strlen($file['content']),
            $selected,
        ));
        $omittedBytes = $eligibleBytes - $selectedBytes;

        return $this->result($selected, $omitted, $budgetBytes, $selectedBytes, $eligibleBytes, $omittedBytes);
    }

    /**
     * @param list<array{path: string, bytes: int, category: string}> $omitted
     * @param list<array{path: string, bytes: int, content: string}> $selected
     *
     * @return array{
     *     files: list<array{path: string, bytes: int, content: string}>,
     *     omitted: list<array{path: string, bytes: int, category: string}>,
     *     selectedBytes: int,
     *     omittedBytes: int,
     *     coverage: array{
     *         mode: 'full'|'selected',
     *         requestedBudgetBytes: ?int,
     *         eligibleFiles: int,
     *         eligibleBytes: int,
     *         selectedFiles: int,
     *         selectedBytes: int,
     *         omittedFiles: int,
     *         omittedBytes: int,
     *         omittedBreakdown: array<string, int>
     *     }
     * }
     */
    private function result(
        array $selected,
        array $omitted,
        ?int $budgetBytes,
        int $selectedBytes,
        int $eligibleBytes,
        ?int $omittedBytes = null,
    ): array {
        $omittedBytes ??= $eligibleBytes - $selectedBytes;
        $breakdown = [];
        foreach ($omitted as $file) {
            $breakdown[$file['category']] = ($breakdown[$file['category']] ?? 0) + 1;
        }

        $isFull = $omitted === [];

        return [
            'files' => $selected,
            'omitted' => $omitted,
            'selectedBytes' => $selectedBytes,
            'omittedBytes' => $omittedBytes,
            'coverage' => [
                'mode' => $isFull ? 'full' : 'selected',
                'requestedBudgetBytes' => $isFull ? null : $budgetBytes,
                'eligibleFiles' => count($selected) + count($omitted),
                'eligibleBytes' => $eligibleBytes,
                'selectedFiles' => count($selected),
                'selectedBytes' => $selectedBytes,
                'omittedFiles' => count($omitted),
                'omittedBytes' => $omittedBytes,
                'omittedBreakdown' => $breakdown,
            ],
        ];
    }

    /**
     * Order candidates by functional priority, using round-robin groups for tests.
     *
     * @param list<array{path: string, bytes: int, content: string}> $files
     *
     * @return list<array{path: string, bytes: int, content: string}>
     */
    private function orderedFiles(array $files): array
    {
        $byTier = [];
        foreach ($files as $file) {
            [$tier, $rank, $group] = $this->priority($file['path']);
            $byTier[$tier][$group][] = [
                'file' => $file,
                'rank' => $rank,
            ];
        }

        $ordered = [];
        ksort($byTier);
        foreach ($byTier as $groups) {
            foreach ($groups as &$groupFiles) {
                usort($groupFiles, static function (array $left, array $right): int {
                    $rank = $left['rank'] <=> $right['rank'];
                    if ($rank !== 0) {
                        return $rank;
                    }

                    return $left['file']['path'] <=> $right['file']['path'];
                });
            }
            unset($groupFiles);

            while ($groups !== []) {
                uasort($groups, static function (array $left, array $right): int {
                    $rank = $left[0]['rank'] <=> $right[0]['rank'];
                    if ($rank !== 0) {
                        return $rank;
                    }

                    return $left[0]['file']['path'] <=> $right[0]['file']['path'];
                });
                $minimumRank = min(array_map(
                    static fn (array $group): int => $group[0]['rank'],
                    $groups,
                ));
                foreach (array_keys($groups) as $key) {
                    if ($groups[$key][0]['rank'] !== $minimumRank) {
                        continue;
                    }

                    $candidate = array_shift($groups[$key]);
                    $ordered[] = $candidate['file'];
                    if ($groups[$key] === []) {
                        unset($groups[$key]);
                    }
                }
            }
        }

        return $ordered;
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    private function priority(string $path): array
    {
        $normalized = strtolower(str_replace('\\', '/', $path));
        $basename = basename($normalized);
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        if ($this->isTestPath($normalized)) {
            return [2, $this->testRank($normalized), $this->testGroup($path)];
        }
        if ($basename === 'composer.json' || str_ends_with($basename, '.info.yml')) {
            return [0, 0, 'definition'];
        }
        if (str_contains($normalized, 'config/schema/') || str_contains($normalized, 'config/install/')) {
            return [0, 1, 'configuration'];
        }
        if ($extension === 'module' || $extension === 'install'
            || str_ends_with($basename, '.routing.yml')
            || str_ends_with($basename, '.services.yml')
            || str_ends_with($basename, '.permissions.yml')
            || str_contains($basename, '.links.')
            || str_ends_with($basename, '.libraries.yml')
        ) {
            return [0, 2, 'runtime'];
        }
        if ($extension === 'php' && str_contains($normalized, '/src/')) {
            return [0, 3, 'runtime'];
        }
        if ($extension === 'php') {
            return [0, 4, 'runtime'];
        }
        if (in_array($extension, ['js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx'], true)
            || $extension === 'twig'
            || str_contains($normalized, '/templates/')
        ) {
            return [1, 0, 'frontend'];
        }
        if ($basename === 'readme' || str_starts_with($basename, 'readme.')) {
            return [1, 1, 'documentation'];
        }
        if (in_array($extension, ['css', 'scss', 'sass', 'less'], true)) {
            return [3, 0, 'styles'];
        }

        return [3, 1, 'other'];
    }

    private function category(string $path): string
    {
        [$tier, , $group] = $this->priority($path);
        if ($this->isTestPath(strtolower(str_replace('\\', '/', $path)))) {
            return 'tests';
        }
        if ($tier === 0) {
            return match ($group) {
                'definition' => 'module_definition',
                'configuration' => 'configuration',
                default => 'runtime',
            };
        }
        if ($tier === 1) {
            return $group;
        }

        return $group;
    }

    private function isTestPath(string $path): bool
    {
        return str_starts_with($path, 'tests/') || str_contains($path, '/tests/');
    }

    private function testRank(string $path): int
    {
        if (str_contains($path, 'functionaljavascript')) {
            return 0;
        }
        if (str_contains($path, '/functional/')) {
            return 1;
        }
        if (str_contains($path, '/kernel/')) {
            return 2;
        }
        if (preg_match('/(?:access|permission|behavior|integration|plugin|config|routing)/', $path) === 1) {
            return 3;
        }
        if (str_contains($path, '/unit/')) {
            return 4;
        }
        if (preg_match('/(?:base|trait|helper|fixture|mock|provider|test_module|test_data)/', $path) === 1) {
            return 6;
        }

        return 5;
    }

    private function testGroup(string $path): string
    {
        $basename = pathinfo(basename($path), PATHINFO_FILENAME);
        $basename = preg_replace('/(?:Kernel|Functional|Unit)?Test$/', '', $basename) ?? $basename;
        preg_match_all('/[A-Z]+(?=[A-Z][a-z]|\b)|[A-Z]?[a-z]+|\d+/', $basename, $matches);
        $words = array_map('strtolower', array_slice($matches[0], 0, 2));

        return $words === [] ? $basename : implode('_', $words);
    }

    /**
     * @param list<mixed> $files
     */
    private function validateFiles(array $files): void
    {
        if (!array_is_list($files)) {
            throw new \InvalidArgumentException('Module review source files must be a list.');
        }

        $paths = [];
        foreach ($files as $file) {
            if (!is_array($file)
                || !is_string($file['path'] ?? null)
                || trim($file['path']) === ''
                || !is_int($file['bytes'] ?? null)
                || $file['bytes'] < 0
                || !is_string($file['content'] ?? null)
            ) {
                throw new \InvalidArgumentException('Module review source file metadata is invalid.');
            }
            if (isset($paths[$file['path']])) {
                throw new \InvalidArgumentException('Module review source file paths must be unique.');
            }
            $paths[$file['path']] = true;
        }
    }
}
