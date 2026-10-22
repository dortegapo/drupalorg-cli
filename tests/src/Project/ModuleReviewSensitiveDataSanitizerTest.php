<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Project;

use mglaman\DrupalOrg\Project\ModuleReviewSensitiveDataSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleReviewSensitiveDataSanitizer::class)]
class ModuleReviewSensitiveDataSanitizerTest extends TestCase
{
    public function testLeavesNormalContentUnchanged(): void
    {
        $files = [self::file('src/Example.php', "<?php\n\nreturn 'normal value';", 999)];

        $result = $this->sanitizer()->sanitize($files);

        self::assertSame($files[0]['content'], $result['files'][0]['content']);
        self::assertSame(strlen($result['files'][0]['content']), $result['files'][0]['bytes']);
        self::assertSame([], $result['redactions']);
    }

    public function testSanitizesPhpCredentialAndPreservesContext(): void
    {
        $result = $this->sanitize("private const CLIENT_SECRET = 'super-secret-value';\n");

        self::assertStringNotContainsString('super-secret-value', $result['files'][0]['content']);
        self::assertStringContainsString("private const CLIENT_SECRET = '[REDACTED_SECRET]';", $result['files'][0]['content']);
        self::assertSame([
            ['path' => 'module.php', 'line' => 1, 'type' => 'credential'],
        ], $result['redactions']);
    }

    public function testSanitizesArrayYamlJsonAndJavascriptCredentials(): void
    {
        $content = implode("\n", [
            '$config[\'api_key\'] = \'real-api-key-value\';',
            'client_secret: secret-value',
            'password: "yaml-password"',
            '{"client_secret":"json-secret"}',
            'const apiKey = \'javascript-api-value\';',
            'let accessToken = "javascript-token-value";',
        ]);

        $result = $this->sanitize($content);

        self::assertStringNotContainsString('real-api-key-value', $result['files'][0]['content']);
        self::assertStringNotContainsString('secret-value', $result['files'][0]['content']);
        self::assertStringNotContainsString('yaml-password', $result['files'][0]['content']);
        self::assertStringNotContainsString('json-secret', $result['files'][0]['content']);
        self::assertStringNotContainsString('javascript-api-value', $result['files'][0]['content']);
        self::assertStringNotContainsString('javascript-token-value', $result['files'][0]['content']);
        self::assertCount(6, $result['redactions']);
    }

    public function testPreservesEnvironmentReferencesAndDynamicValues(): void
    {
        $content = implode("\n", [
            "client_secret: '%env(CLIENT_SECRET)%'",
            'api_key: ${MY_API_KEY}',
            '$password = $API_PASSWORD;',
            '$password = getenv(\'PASSWORD\');',
            '$_ENV[\'CLIENT_SECRET\'];',
        ]);

        $result = $this->sanitize($content);

        self::assertSame($content, $result['files'][0]['content']);
        self::assertSame([], $result['redactions']);
    }

    public function testSanitizesBearerAndUrlCredentials(): void
    {
        $content = "Authorization: Bearer abcdefghijklmnopqrstuvwxyz012345\nhttps://john:secret-password@example.com/api";

        $result = $this->sanitize($content);

        self::assertStringContainsString('Bearer [REDACTED_AUTHORIZATION]', $result['files'][0]['content']);
        self::assertStringContainsString(
            'https://john:[REDACTED_URL_CREDENTIAL]@example.com/api',
            $result['files'][0]['content'],
        );
        self::assertSame(
            ['authorization', 'url_credentials'],
            array_column($result['redactions'], 'type'),
        );
    }

    public function testSanitizesPemAsOneRedactionWithoutChangingLineCount(): void
    {
        $content = "before\n-----BEGIN PRIVATE KEY-----\nSECRET-MATERIAL\n-----END PRIVATE KEY-----\nafter";
        $result = $this->sanitize($content);
        $sanitized = $result['files'][0]['content'];

        self::assertStringNotContainsString('SECRET-MATERIAL', $sanitized);
        self::assertSame(5, substr_count($content, "\n") + 1);
        self::assertSame(5, substr_count($sanitized, "\n") + 1);
        self::assertSame(
            "before\n[REDACTED_PRIVATE_KEY]\n[REDACTED_PRIVATE_KEY]\n[REDACTED_PRIVATE_KEY]\nafter",
            $sanitized,
        );
        self::assertSame([
            ['path' => 'module.php', 'line' => 2, 'type' => 'private_key'],
        ], $result['redactions']);
    }

    public function testSanitizesUnterminatedPemUntilEndOfFile(): void
    {
        $content = "line 1\n-----BEGIN PRIVATE KEY-----\nSECRET-A\nSECRET-B";

        $result = $this->sanitize($content);
        $sanitized = $result['files'][0]['content'];

        self::assertStringNotContainsString('SECRET-A', $sanitized);
        self::assertStringNotContainsString('SECRET-B', $sanitized);
        self::assertSame(4, substr_count($sanitized, "\n") + 1);
        self::assertSame([
            ['path' => 'module.php', 'line' => 2, 'type' => 'private_key'],
        ], $result['redactions']);
    }

    public function testPreservesDynamicBearerReferences(): void
    {
        $content = implode("\n", [
            'Authorization: Bearer $token',
            'Authorization: Bearer ${ACCESS_TOKEN}',
            'Authorization: Bearer %env(ACCESS_TOKEN)%',
        ]);

        $result = $this->sanitize($content);

        self::assertSame($content, $result['files'][0]['content']);
        self::assertSame([], $result['redactions']);
    }

    public function testSanitizesKnownTokensButNotGenericHashes(): void
    {
        $content = implode("\n", [
            'openai = sk-abcdefghijklmnopqrstuvwxyz123456',
            'github = ghp_abcdefghijklmnopqrstuvwxyz123456',
            'fine = github_pat_abcdefghijklmnopqrstuvwxyz123456',
            'jwt = eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.signaturevalue123',
            'commit = 0123456789abcdef0123456789abcdef01234567',
            'uuid = 550e8400-e29b-41d4-a716-446655440000',
        ]);

        $result = $this->sanitize($content);

        self::assertSame(4, substr_count($result['files'][0]['content'], '[REDACTED_TOKEN]'));
        self::assertStringContainsString('0123456789abcdef0123456789abcdef01234567', $result['files'][0]['content']);
        self::assertStringContainsString('550e8400-e29b-41d4-a716-446655440000', $result['files'][0]['content']);
        self::assertSame(4, count($result['redactions']));
    }

    public function testSanitizesEscapedPrivateKeyValue(): void
    {
        $content = '"private_key": "-----BEGIN PRIVATE KEY-----\\nABCDEF\\n-----END PRIVATE KEY-----\\n"';

        $result = $this->sanitize($content);

        self::assertStringContainsString('"private_key": "[REDACTED_SECRET]"', $result['files'][0]['content']);
        self::assertStringNotContainsString('ABCDEF', $result['files'][0]['content']);
        self::assertSame('credential', $result['redactions'][0]['type']);
    }

    public function testIsIdempotentForRedactionMarkers(): void
    {
        $content = implode("\n", [
            "\$apiKey = '[REDACTED_SECRET]';",
            "\$password = '[REDACTED_SECRET]';",
            "\$accessToken = '[REDACTED_TOKEN]';",
            'https://user:[REDACTED_URL_CREDENTIAL]@example.com',
            'Authorization: Bearer [REDACTED_AUTHORIZATION]',
        ]);

        $result = $this->sanitize($content);

        self::assertSame($content, $result['files'][0]['content']);
        self::assertSame([], $result['redactions']);
    }

    public function testDoesNotSanitizeJwtLikeIdentifier(): void
    {
        $content = implode("\n", [
            'longordinaryidentifier.partoneidentifier.parttwoidentifier',
            '1.2.3',
            '192.168.1.1',
            'version.2024.release',
        ]);

        $result = $this->sanitize($content);

        self::assertSame($content, $result['files'][0]['content']);
        self::assertSame([], $result['redactions']);
    }

    public function testPreservesFileOrderAndRedactionOrder(): void
    {
        $files = [
            self::file('first.php', "password = 'one'\napi_key = 'two'"),
            self::file('second.php', "Bearer thirdtoken\nclient_secret = 'four'"),
        ];

        $result = $this->sanitizer()->sanitize($files);

        self::assertSame(['first.php', 'second.php'], array_column($result['files'], 'path'));
        self::assertSame(
            [
                ['path' => 'first.php', 'line' => 1, 'type' => 'credential'],
                ['path' => 'first.php', 'line' => 2, 'type' => 'credential'],
                ['path' => 'second.php', 'line' => 1, 'type' => 'authorization'],
                ['path' => 'second.php', 'line' => 2, 'type' => 'credential'],
            ],
            $result['redactions'],
        );
    }

    public function testRecalculatesBytesAndDoesNotMutateInput(): void
    {
        $files = [self::file('secret.php', "password = 'a-very-long-secret-value'")];
        $copy = $files;

        $result = $this->sanitizer()->sanitize($files);

        self::assertSame($copy, $files);
        self::assertSame(strlen($result['files'][0]['content']), $result['files'][0]['bytes']);
        self::assertNotSame($files[0]['bytes'], $result['files'][0]['bytes']);
    }

    #[DataProvider('invalidInputProvider')]
    public function testRejectsInvalidInput(mixed $files): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Module review sensitive data input is invalid.');

        $this->sanitizer()->sanitize($files);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'not a list' => [['file' => self::file('file.php', '')]];
        yield 'file not array' => [['file.php']];
        yield 'missing path' => [[['bytes' => 0, 'content' => '']]];
        yield 'empty path' => [[self::file('', '')]];
        yield 'bytes not integer' => [[self::file('file.php', '', '1')]];
        yield 'negative bytes' => [[self::file('file.php', '', -1)]];
        yield 'content not string' => [[self::file('file.php', 123)]];
    }

    private function sanitizer(): ModuleReviewSensitiveDataSanitizer
    {
        return new ModuleReviewSensitiveDataSanitizer();
    }

    /**
     * @return array{files: list<array{path: string, bytes: int, content: string}>, redactions: list<array<string, mixed>>}
     */
    private function sanitize(string $content): array
    {
        return $this->sanitizer()->sanitize([self::file('module.php', $content)]);
    }

    /**
     * @return array{path: string, bytes: int, content: string}
     */
    private static function file(string $path, mixed $content, mixed $bytes = 0): array
    {
        return [
            'path' => $path,
            'bytes' => $bytes,
            'content' => $content,
        ];
    }
}
