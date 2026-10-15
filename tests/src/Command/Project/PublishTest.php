<?php

namespace mglaman\DrupalOrg\Tests\Command\Project;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use mglaman\DrupalOrg\Client;
use mglaman\DrupalOrgCli\Application;
use mglaman\DrupalOrgCli\Command\Project\Publish;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Publish::class)]
class PublishTest extends TestCase
{
    /**
     * @var array<int, array{request: RequestInterface}>
     */
    private array $history = [];

    private string $originalCwd;

    private string $workDir;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->workDir = sys_get_temp_dir() . '/drupalorg-cli-publish-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        self::removeDirectory($this->workDir);
    }

    public function testExistingDirectoryPrintsResolvedModulePath(): void
    {
        $modulePath = $this->createValidModule();
        chdir($this->workDir);

        $tester = $this->commandTester(self::jsonResponse([
            'list' => [
                [
                    'nid' => '123',
                    'title' => 'My Module',
                    'field_project_machine_name' => 'my_module',
                ],
            ],
        ]));

        $exitCode = $tester->execute(['module' => 'my_module']);

        self::assertSame(0, $exitCode);
        $resolvedPath = realpath($modulePath);
        self::assertSame(
            "Module path: $resolvedPath\nModule info file: $resolvedPath/my_module.info.yml\nModule name: My Module\nModule type: module\nCore version requirement: ^11\nDrupal.org project exists: my_module\n",
            $tester->getDisplay()
        );
    }

    public function testMissingDrupalOrgProjectIsReported(): void
    {
        $this->createValidModule();
        chdir($this->workDir);

        $tester = $this->commandTester(self::jsonResponse(['list' => []]));

        $exitCode = $tester->execute(['module' => 'my_module']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString(
            'Drupal.org project does not exist: my_module',
            $tester->getDisplay()
        );
    }

    public function testDrupalOrgErrorIsNotReportedAsMissingProject(): void
    {
        $this->createValidModule();
        chdir($this->workDir);

        $tester = $this->commandTester(new Response(500, [], 'Server error'));

        $exitCode = $tester->execute(['module' => 'my_module']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Could not check Drupal.org project:', $tester->getDisplay());
        self::assertStringNotContainsString('project does not exist', $tester->getDisplay());
    }

    public function testDryRunOptionIsRegisteredAsValueNone(): void
    {
        $command = (new Application())->find('project:publish');

        self::assertTrue($command->getDefinition()->hasOption('dry-run'));
        self::assertFalse($command->getDefinition()->getOption('dry-run')->acceptValue());
    }

    public function testDryRunReportsPlanForMissingDrupalOrgProject(): void
    {
        $modulePath = $this->createValidModule();
        chdir($this->workDir);

        $tester = $this->commandTester(self::jsonResponse(['list' => []]));

        $exitCode = $tester->execute([
            'module' => 'my_module',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        $resolvedPath = realpath($modulePath);
        self::assertSame(
            "Dry run: no changes will be made.\n\n"
            . "Module path: $resolvedPath\n"
            . "Machine name: my_module\n"
            . "Module info file: $resolvedPath/my_module.info.yml\n"
            . "Module name: My Module\n"
            . "Module type: module\n"
            . "Core version requirement: ^11\n"
            . "Drupal.org project exists: no\n\n"
            . "Planned actions:\n"
            . "  1. Authenticate with Drupal.org automatically if required.\n"
            . "  2. Create Drupal.org project \"my_module\".\n"
            . "  3. Wait for repository provisioning.\n"
            . "  4. Prepare an isolated temporary workspace.\n"
            . "  5. Copy the module into the temporary workspace.\n"
            . "  6. Initialize the publication Git repository.\n"
            . "  7. Create the initial commit.\n"
            . "  8. Configure the Drupal.org Git remote.\n"
            . "  9. Push the initial branch.\n\n"
            . "Publication execution is not implemented yet.\n"
            . "Dry run complete. No changes were made.\n",
            $tester->getDisplay()
        );

        self::assertCount(1, $this->history);
        self::assertSame('GET', $this->sentRequest()->getMethod());
        self::assertSame(
            'https://www.drupal.org/api-d7/node.json?field_project_machine_name=my_module',
            (string) $this->sentRequest()->getUri()
        );
    }

    public function testDryRunReportsPlanForExistingDrupalOrgProject(): void
    {
        $this->createValidModule();
        chdir($this->workDir);

        $tester = $this->commandTester(self::jsonResponse([
            'list' => [
                [
                    'nid' => '123',
                    'title' => 'My Module',
                    'field_project_machine_name' => 'my_module',
                ],
            ],
        ]));

        $exitCode = $tester->execute([
            'module' => 'my_module',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Drupal.org project exists: yes', $tester->getDisplay());
        self::assertStringContainsString(
            'Use the existing Drupal.org project "my_module".',
            $tester->getDisplay()
        );
        self::assertStringNotContainsString(
            'Create Drupal.org project "my_module".',
            $tester->getDisplay()
        );
        self::assertStringContainsString(
            'Publication execution is not implemented yet.',
            $tester->getDisplay()
        );
    }

    public function testInvalidYamlFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents($modulePath . '/my_module.info.yml', "name: [invalid\n");

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Could not parse module info file:', $tester->getDisplay());
    }

    public function testYamlWithoutNameFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents($modulePath . '/my_module.info.yml', "type: module\ncore_version_requirement: ^11\n");

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Missing required "name" in my_module.info.yml.',
            $tester->getDisplay()
        );
    }

    public function testYamlWithEmptyNameFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents(
            $modulePath . '/my_module.info.yml',
            "name: \"\"\ntype: module\ncore_version_requirement: ^11\n"
        );

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Required "name" in my_module.info.yml must be a non-empty string.',
            $tester->getDisplay()
        );
    }

    public function testYamlWithoutTypeFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents($modulePath . '/my_module.info.yml', "name: My Module\ncore_version_requirement: ^11\n");

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Missing required "type" in my_module.info.yml.',
            $tester->getDisplay()
        );
    }

    public function testYamlWithThemeTypeFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents(
            $modulePath . '/my_module.info.yml',
            "name: My Module\ntype: theme\ncore_version_requirement: ^11\n"
        );

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Expected Drupal project type "module", got "theme".',
            $tester->getDisplay()
        );
    }

    public function testYamlWithoutCoreVersionRequirementFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents($modulePath . '/my_module.info.yml', "name: My Module\ntype: module\n");

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Missing required "core_version_requirement" in my_module.info.yml.',
            $tester->getDisplay()
        );
    }

    public function testYamlWithEmptyCoreVersionRequirementFails(): void
    {
        $modulePath = $this->workDir . '/my_module';
        mkdir($modulePath);
        file_put_contents(
            $modulePath . '/my_module.info.yml',
            "name: My Module\ntype: module\ncore_version_requirement: \"\"\n"
        );

        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['module' => $modulePath]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Required "core_version_requirement" in my_module.info.yml must be a non-empty string.',
            $tester->getDisplay()
        );
    }

    public function testModuleArgumentIsRequired(): void
    {
        $command = (new Application())->find('project:publish');
        $tester = new CommandTester($command);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "module").');
        $tester->execute([]);
    }

    private function createValidModule(): string
    {
        $modulePath = $this->workDir . '/web/modules/custom/my_module';
        mkdir($modulePath, 0755, true);
        file_put_contents(
            $modulePath . '/my_module.info.yml',
            "name: My Module\ntype: module\ncore_version_requirement: ^11\n"
        );

        return $modulePath;
    }

    private function commandTester(Response ...$responses): CommandTester
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $client = new Client(false, $stack);
        $command = new class($client) extends Publish {
            public function __construct(private readonly Client $testClient)
            {
                parent::__construct();
            }

            protected function initialize(InputInterface $input, OutputInterface $output): void
            {
                parent::initialize($input, $output);
                $this->client = $this->testClient;
            }
        };

        return new CommandTester($command);
    }

    private function sentRequest(): RequestInterface
    {
        self::assertArrayHasKey(0, $this->history);

        return $this->history[0]['request'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(array $body): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    private static function removeDirectory(string $directory): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
