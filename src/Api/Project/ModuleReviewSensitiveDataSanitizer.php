<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

final class ModuleReviewSensitiveDataSanitizer
{
    private const INVALID_INPUT_MESSAGE = 'Module review sensitive data input is invalid.';

    private const SECRET_MARKER = '[REDACTED_SECRET]';

    private const TOKEN_MARKER = '[REDACTED_TOKEN]';

    private const AUTHORIZATION_MARKER = '[REDACTED_AUTHORIZATION]';

    private const PRIVATE_KEY_MARKER = '[REDACTED_PRIVATE_KEY]';

    private const URL_CREDENTIAL_MARKER = '[REDACTED_URL_CREDENTIAL]';

    /**
     * @param list<array{
     *     path: string,
     *     bytes: int,
     *     content: string
     * }> $files
     *
     * @return array{
     *     files: list<array{path: string, bytes: int, content: string}>,
     *     redactions: list<array{
     *         path: string,
     *         line: int,
     *         type: 'credential'|'token'|'authorization'|'private_key'|'url_credentials'
     *     }>
     * }
     */
    public function sanitize(array $files): array
    {
        $this->validateFiles($files);

        $sanitizedFiles = [];
        $redactions = [];
        foreach ($files as $file) {
            [$content, $fileRedactions] = $this->sanitizeContent($file['path'], $file['content']);
            $sanitizedFiles[] = [
                'path' => $file['path'],
                'bytes' => strlen($content),
                'content' => $content,
            ];
            foreach ($fileRedactions as $redaction) {
                $redactions[] = $redaction;
            }
        }

        return [
            'files' => $sanitizedFiles,
            'redactions' => $redactions,
        ];
    }

    /**
     * @param list<mixed> $files
     */
    private function validateFiles(array $files): void
    {
        if (!array_is_list($files)) {
            $this->invalidInput();
        }

        foreach ($files as $file) {
            if (!is_array($file)
                || !array_key_exists('path', $file)
                || !is_string($file['path'])
                || trim($file['path']) === ''
                || !array_key_exists('bytes', $file)
                || !is_int($file['bytes'])
                || $file['bytes'] < 0
                || !array_key_exists('content', $file)
                || !is_string($file['content'])
            ) {
                $this->invalidInput();
            }
        }
    }

    /**
     * @return array{0: string, 1: list<array{path: string, line: int, type: string}>}
     */
    private function sanitizeContent(string $path, string $content): array
    {
        /** @var list<array{offset: int, length: int, replacement: string, line: int, type: string}> $matches */
        $matches = [];

        $this->detectPrivateKeys($content, $matches);
        $this->detectSensitiveAssignments($content, $matches);
        $this->detectBearerTokens($content, $matches);
        $this->detectUrlCredentials($content, $matches);
        $this->detectKnownTokens($content, $matches);

        usort($matches, static function (array $left, array $right): int {
            return $left['offset'] <=> $right['offset'];
        });

        $sanitized = '';
        $cursor = 0;
        $redactions = [];
        foreach ($matches as $match) {
            if ($match['offset'] < $cursor) {
                continue;
            }

            $sanitized .= substr($content, $cursor, $match['offset'] - $cursor);
            $sanitized .= $match['replacement'];
            $cursor = $match['offset'] + $match['length'];
            $redactions[] = [
                'path' => $path,
                'line' => $match['line'],
                'type' => $match['type'],
            ];
        }
        $sanitized .= substr($content, $cursor);

        return [$sanitized, $redactions];
    }

    /**
     * @param list<array{offset: int, length: int, replacement: string, line: int, type: string}> $matches
     */
    private function detectPrivateKeys(string $content, array &$matches): void
    {
        preg_match_all(
            '/-----BEGIN ((?:[A-Z]+ )*PRIVATE KEY)-----(?:\r\n|\r|\n).*?-----END \\1-----/s',
            $content,
            $found,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($found[0] as [$value, $offset]) {
            $matches[] = [
                'offset' => $offset,
                'length' => strlen($value),
                'replacement' => $this->replacePrivateKeyBlock($value),
                'line' => $this->lineAtOffset($content, $offset),
                'type' => 'private_key',
            ];
        }
    }

    /**
     * @param list<array{offset: int, length: int, replacement: string, line: int, type: string}> $matches
     */
    private function detectSensitiveAssignments(string $content, array &$matches): void
    {
        $keyPattern = '(?:password|passwd|client[_-]?secret|api[_-]?key|access[_-]?token|refresh[_-]?token|auth[_-]?token|bearer[_-]?token|private[_-]?key|secret[_-]?key)';
        $pattern = '/(?<![A-Za-z0-9_])(?<key>' . $keyPattern . ')(?![A-Za-z0-9_])(?:[\'\"]\s*)?\]?\s*(?:=>|=|:)\s*(?:\'(?<single>(?:\\\\.|[^\'\\\\\r\n])*)\'|"(?<double>(?:\\\\.|[^"\\\\\r\n])*)"|(?<bare>\$\{[^}\r\n]+\}|%env\([^\r\n]+\)%|[^\s,;)}\]\\r\\n]+))/i';
        preg_match_all($pattern, $content, $found, PREG_OFFSET_CAPTURE);

        foreach ($found[0] as $index => [$fullMatch, $fullOffset]) {
            $value = $this->capturedValue($found, 'single', $index);
            $valueOffset = $this->capturedOffset($found, 'single', $index);
            if ($value === null) {
                $value = $this->capturedValue($found, 'double', $index);
                $valueOffset = $this->capturedOffset($found, 'double', $index);
            }
            if ($value === null) {
                $value = $this->capturedValue($found, 'bare', $index);
                $valueOffset = $this->capturedOffset($found, 'bare', $index);
            }

            if ($value === null || $value === '' || $this->isSafeReference($value)) {
                continue;
            }

            $key = $found['key'][$index][0];
            $replacement = self::SECRET_MARKER;
            $type = 'credential';
            if (preg_match('/private[_-]?key/i', $key) === 1) {
                $replacement = self::PRIVATE_KEY_MARKER;
                $type = 'private_key';
                if (str_contains(strtoupper(stripslashes($value)), 'BEGIN ')) {
                    $replacement = self::SECRET_MARKER;
                    $type = 'credential';
                }
            } elseif (preg_match('/(?:token)/i', $key) === 1) {
                $replacement = self::TOKEN_MARKER;
                $type = 'token';
            }

            if ($valueOffset === null || $this->overlaps($valueOffset, strlen($value), $matches)) {
                continue;
            }

            $matches[] = [
                'offset' => $valueOffset,
                'length' => strlen($value),
                'replacement' => $replacement,
                'line' => $this->lineAtOffset($content, $fullOffset),
                'type' => $type,
            ];
        }
    }

    /**
     * @param list<array{offset: int, length: int, replacement: string, line: int, type: string}> $matches
     */
    private function detectBearerTokens(string $content, array &$matches): void
    {
        preg_match_all('/\bBearer\s+([^\s,;\'"\]]+)/i', $content, $found, PREG_OFFSET_CAPTURE);
        foreach ($found[1] as $index => [$value, $offset]) {
            if (str_starts_with($value, '[REDACTED_') || $this->overlaps($offset, strlen($value), $matches)) {
                continue;
            }

            $matches[] = [
                'offset' => $offset,
                'length' => strlen($value),
                'replacement' => self::AUTHORIZATION_MARKER,
                'line' => $this->lineAtOffset($content, $offset),
                'type' => 'authorization',
            ];
        }
    }

    /**
     * @param list<array{offset: int, length: int, replacement: string, line: int, type: string}> $matches
     */
    private function detectUrlCredentials(string $content, array &$matches): void
    {
        preg_match_all(
            '~\bhttps?://([^/\s:@]+):([^@\s/]+)@~i',
            $content,
            $found,
            PREG_OFFSET_CAPTURE,
        );
        foreach ($found[2] as $index => [$value, $offset]) {
            if ($this->isSafeReference($value) || $this->overlaps($offset, strlen($value), $matches)) {
                continue;
            }

            $matches[] = [
                'offset' => $offset,
                'length' => strlen($value),
                'replacement' => self::URL_CREDENTIAL_MARKER,
                'line' => $this->lineAtOffset($content, $offset),
                'type' => 'url_credentials',
            ];
        }
    }

    /**
     * @param list<array{offset: int, length: int, replacement: string, line: int, type: string}> $matches
     */
    private function detectKnownTokens(string $content, array &$matches): void
    {
        $patterns = [
            '/\bsk-[A-Za-z0-9_-]{20,}\b/',
            '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}\b/',
            '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
            '/\b[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $found, PREG_OFFSET_CAPTURE);
            foreach ($found[0] as [$value, $offset]) {
                if ($this->overlaps($offset, strlen($value), $matches)) {
                    continue;
                }

                $matches[] = [
                    'offset' => $offset,
                    'length' => strlen($value),
                    'replacement' => self::TOKEN_MARKER,
                    'line' => $this->lineAtOffset($content, $offset),
                    'type' => 'token',
                ];
            }
        }
    }

    private function replacePrivateKeyBlock(string $value): string
    {
        return preg_replace_callback(
            '/\r\n|\r|\n/',
            static fn (): string => "\n",
            preg_replace('/[^\r\n]+/', self::PRIVATE_KEY_MARKER, $value) ?? $value,
        ) ?? self::PRIVATE_KEY_MARKER;
    }

    /**
     * @param array<string, array<int, array{0: string, 1: int}>> $found
     */
    private function capturedValue(array $found, string $name, int $index): ?string
    {
        $value = $found[$name][$index][0] ?? null;
        $offset = $found[$name][$index][1] ?? -1;

        return $offset === -1 ? null : $value;
    }

    /**
     * @param array<string, array<int, array{0: string, 1: int}>> $found
     */
    private function capturedOffset(array $found, string $name, int $index): ?int
    {
        $offset = $found[$name][$index][1] ?? -1;

        return $offset === -1 ? null : $offset;
    }

    private function isSafeReference(string $value): bool
    {
        $value = trim($value);

        return preg_match(
            '/^(?:%env\([^)]*\)%|\$\{[^}]+\}|\$[A-Za-z_][A-Za-z0-9_]*;?|(?:getenv|env)\s*\()/',
            $value,
        ) === 1;
    }

    /**
     * @param list<array{offset: int, length: int, replacement: string, line: int, type: string}> $matches
     */
    private function overlaps(int $offset, int $length, array $matches): bool
    {
        foreach ($matches as $match) {
            if ($offset < $match['offset'] + $match['length']
                && $offset + $length > $match['offset']
            ) {
                return true;
            }
        }

        return false;
    }

    private function lineAtOffset(string $content, int $offset): int
    {
        $prefix = substr($content, 0, $offset);
        preg_match_all('/\r\n|\r|\n/', $prefix, $matches);

        return count($matches[0]) + 1;
    }

    private function invalidInput(): never
    {
        throw new \InvalidArgumentException(self::INVALID_INPUT_MESSAGE);
    }
}
