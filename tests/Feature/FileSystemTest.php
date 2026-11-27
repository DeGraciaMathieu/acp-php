<?php

declare(strict_types=1);

namespace Acp\Tests\Feature;

use Acp\Client\FileSystem\LocalFileSystem;
use Acp\Exception\RpcError;
use Acp\Schema\ErrorCode;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileSystemTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/acp-fs-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    #[Test]
    public function the_agent_reads_and_writes_files_through_the_client(): void
    {
        file_put_contents($this->root . '/style.css', "body {\n  background: #fff;\n}\n");

        $results = $this->runAgent(function (FakeTurn $turn, string $root): array {
            $line = $turn->readFile($root . '/style.css', line: 2, limit: 1);
            $turn->writeFile($root . '/dist/style.css', 'body { background: #111; }');

            return ['line' => $line];
        });

        self::assertSame("  background: #fff;\n", $results['line']);
        self::assertSame('body { background: #111; }', file_get_contents($this->root . '/dist/style.css'));
    }

    #[Test]
    public function the_agent_cannot_leave_the_root_directory(): void
    {
        $error = $this->agentError(fn (FakeTurn $turn, string $root) => $turn->readFile($root . '/../etc/passwd'));

        self::assertStringContainsString('Access outside of', $error->getMessage());
    }

    #[Test]
    public function a_missing_file_is_reported_to_the_agent(): void
    {
        $error = $this->agentError(fn (FakeTurn $turn, string $root) => $turn->readFile($root . '/missing.css'));

        self::assertSame(ErrorCode::ResourceNotFound, $error->errorCode());
    }

    #[Test]
    public function relative_paths_are_refused(): void
    {
        $error = $this->agentError(fn (FakeTurn $turn) => $turn->readFile('style.css'));

        self::assertSame(ErrorCode::InvalidParams, $error->errorCode());
    }

    #[Test]
    public function the_root_directory_must_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalFileSystem($this->root . '/missing');
    }

    #[Test]
    public function capabilities_follow_the_handlers(): void
    {
        $agent = FakeAgent::create();
        $agent->connect()->initialize();
        $agent->connect(fileSystem: new LocalFileSystem($this->root))->initialize();

        [$without, $with] = $agent->received('initialize');
        self::assertFalse($without['clientCapabilities']['fs']['readTextFile'] ?? false);
        self::assertTrue($with['clientCapabilities']['fs']['readTextFile']);
        self::assertTrue($with['clientCapabilities']['fs']['writeTextFile']);
    }

    /**
     * Runs one turn where the agent works in the root directory, and returns what it collected.
     *
     * @param \Closure(FakeTurn, string): array<string, mixed> $work
     *
     * @return array<string, mixed>
     */
    private function runAgent(\Closure $work): array
    {
        $results = [];
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn) use ($work, &$results): null {
                $results = $work($turn, $this->root);

                return null;
            })
            ->connect(fileSystem: new LocalFileSystem($this->root));
        $client->initialize();

        $client->newSession(cwd: $this->root)->prompt('work')->wait();

        return $results;
    }

    /**
     * @param \Closure(FakeTurn, string): mixed $work
     */
    private function agentError(\Closure $work): RpcError
    {
        $results = $this->runAgent(function (FakeTurn $turn, string $root) use ($work): array {
            try {
                $work($turn, $root);
            } catch (RpcError $e) {
                return ['error' => $e];
            }

            return [];
        });

        self::assertInstanceOf(RpcError::class, $results['error'] ?? null, 'The agent should have received an error.');

        return $results['error'];
    }
}
