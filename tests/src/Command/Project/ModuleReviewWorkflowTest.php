<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Tests\Command\Project;

use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrgCli\Command\Project\ModuleReviewWorkflow;
use mglaman\DrupalOrgCli\Command\Project\ModuleReviewWorkflowResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(ModuleReviewWorkflow::class)]
final class ModuleReviewWorkflowTest extends TestCase
{
    public function testUnavailableRuntimeSkipsOptionalReview(): void
    {
        $workflow = new ModuleReviewWorkflow(
            new Client(),
            static fn (?string $projectRoot): ?array => null,
        );

        $result = $workflow->run(
            new ArrayInput([]),
            new BufferedOutput(),
            new QuestionHelper(),
            self::module(),
            self::moduleInfo(),
        );

        self::assertTrue($result->continuePublishing);
        self::assertNull($result->runtime);
        self::assertNull($result->analysisResult);
    }

    public function testReviewCancellationIsRepresentedInTheResult(): void
    {
        $workflow = new ModuleReviewWorkflow(
            new Client(),
            static fn (?string $projectRoot): array => [
                'type' => 'local-drush',
                'workingDirectory' => '/tmp',
                'command' => ['drush'],
            ],
            static fn (array $runtime): array => [
                'aiEnabled' => true,
                'providerId' => 'provider',
                'modelId' => 'model',
            ],
            static fn (string $modulePath): array => [
                'files' => [['path' => 'src/Example.php', 'bytes' => 20, 'content' => '<?php']],
                'skipped' => [],
                'totalBytes' => 20,
                'redactions' => [],
                'coverage' => [],
            ],
            static fn (...$arguments): array => [
                'complete' => false,
                'limitations' => [['stage' => 'review_ai', 'message' => 'Unavailable']],
            ],
        );
        $input = new ArrayInput([]);
        $input->setInteractive(true);
        $input->setStream(self::inputStream("y\nn\n"));

        $result = $workflow->run(
            $input,
            new BufferedOutput(),
            new QuestionHelper(),
            self::module(),
            self::moduleInfo(),
        );

        self::assertInstanceOf(ModuleReviewWorkflowResult::class, $result);
        self::assertFalse($result->continuePublishing);
        self::assertNotNull($result->runtime);
        self::assertNotNull($result->analysisResult);
    }

    /** @return array{resolvedPath: string, moduleName: string, infoFile: string, projectRoot: ?string, webRoot: ?string} */
    private static function module(): array
    {
        return [
            'resolvedPath' => '/tmp/example',
            'moduleName' => 'example',
            'infoFile' => '/tmp/example/example.info.yml',
            'projectRoot' => '/tmp',
            'webRoot' => 'web',
        ];
    }

    /** @return array{name: string, type: string, coreVersionRequirement: string, dependencies: list<string>} */
    private static function moduleInfo(): array
    {
        return [
            'name' => 'Example',
            'type' => 'module',
            'coreVersionRequirement' => '^11',
            'dependencies' => [],
        ];
    }

    /** @return resource */
    private static function inputStream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
