<?php

declare(strict_types=1);

namespace Acp\Tests;

use Acp\AgentProcess;
use Acp\Exception\ConnectionClosed;
use Acp\Schema\SessionUpdate;
use Acp\Schema\StopReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentProcessTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/Fixtures/fake-agent.php';

    #[Test]
    public function a_real_agent_subprocess_over_stdio(): void
    {
        $logs = [];
        $cwd = sys_get_temp_dir();
        $agent = AgentProcess::start(
            PHP_BINARY,
            [self::SCRIPT],
            cwd: $cwd,
            env: ['ACP_TEST' => 'yes'],
            onStderr: function (string $line) use (&$logs): void {
                $logs[] = $line;
            },
        );

        $client = $agent->connect();
        $client->initialize();
        $session = $client->newSession(cwd: $cwd);
        $turn = $session->prompt('Bonjour');

        $updates = iterator_to_array($turn->updates(), false);

        self::assertInstanceOf(SessionUpdate\ToolCall::class, $updates[0]);
        self::assertSame('Read ' . realpath($cwd), $updates[0]->title);
        self::assertSame(StopReason::EndTurn, $turn->stopReason());
        self::assertSame('Hello from PID ' . $agent->pid() . ', env ACP_TEST=yes', $turn->text());

        self::assertSame(0, $agent->stop());
        self::assertFalse($agent->isRunning());
        self::assertContains('prompt: Bonjour', $logs);
        self::assertContains('fake agent started in normal mode', $agent->stderr());
    }

    #[Test]
    public function environment_variables_can_be_removed(): void
    {
        putenv('ACP_TEST=inherited');
        try {
            $agent = AgentProcess::start(PHP_BINARY, [self::SCRIPT], env: ['ACP_TEST' => null]);
            $client = $agent->connect();
            $client->initialize();
            $text = $client->newSession(cwd: sys_get_temp_dir())->prompt('hi')->text();
            $agent->stop();
        } finally {
            putenv('ACP_TEST');
        }

        self::assertStringEndsWith('ACP_TEST=unset', $text);
    }

    #[Test]
    public function an_agent_that_exits_early(): void
    {
        $agent = AgentProcess::start(PHP_BINARY, [self::SCRIPT, 'crash']);
        $client = $agent->connect();

        try {
            $client->initialize();
            self::fail('The connection should have closed.');
        } catch (ConnectionClosed) {
        }

        self::assertSame(3, $agent->wait());
        self::assertContains('fatal: missing credentials', $agent->stderr());
    }
}
