<?php

declare(strict_types=1);

namespace Acp\Tests\Client;

use Acp\Client\FileSystem\LocalFileSystem;
use Acp\Client\Permission\ApproveAll;
use Acp\Client\Permission\CallbackPermissions;
use Acp\Client\Permission\Outcome;
use Acp\Exception\CapabilityNotSupported;
use Acp\Exception\RpcError;
use Acp\Exception\TurnInProgress;
use Acp\Exception\UnsupportedProtocolVersion;
use Acp\Schema\AgentCapabilities;
use Acp\Schema\ContentBlock\TextContent;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;
use Acp\Schema\SessionUpdate;
use Acp\Schema\StopReason;
use Acp\Schema\ToolCallStatus;
use Acp\Schema\ToolKind;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;
use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class ClientConnectionTest extends TestCase
{
    public function test_a_full_prompt_turn(): void
    {
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn): StopReason {
                $turn->plan('Read the CSS', 'Darken the colors');
                $turn->toolCall('call_1', 'Read style.css', ToolKind::Read);
                $turn->updateToolCall('call_1', ToolCallStatus::Completed, 'body { background: #fff; }');
                $turn->say('Done: ');
                $turn->say('the theme is dark.');

                return StopReason::EndTurn;
            })
            ->connect();

        $init = $client->initialize();
        self::assertSame('fake-agent', $init->agentInfo?->name);
        self::assertTrue($client->agentCapabilities()->loadSession);

        $session = $client->newSession(cwd: '/tmp');
        self::assertSame('code', $session->modes()?->currentModeId);

        $turn = $session->prompt('Switch to a dark theme');
        $kinds = [];
        foreach ($turn->updates() as $update) {
            $kinds[] = $update::class;
        }

        self::assertSame([
            SessionUpdate\Plan::class,
            SessionUpdate\ToolCall::class,
            SessionUpdate\ToolCallUpdate::class,
            SessionUpdate\AgentMessageChunk::class,
            SessionUpdate\AgentMessageChunk::class,
        ], $kinds);
        self::assertSame(StopReason::EndTurn, $turn->stopReason());
        self::assertSame('Done: the theme is dark.', $turn->text());
    }

    public function test_waiting_for_the_text_does_not_require_reading_updates(): void
    {
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn): null {
                $turn->say('You said: ' . $turn->prompt());

                return null;
            })
            ->connect();
        $client->initialize();

        $session = $client->newSession(cwd: '/tmp');

        self::assertSame('You said: hello', $session->prompt('hello')->text());
        self::assertSame('You said: again', $session->prompt([new TextContent('again')])->text());
    }

    public function test_permissions_are_rejected_by_default(): void
    {
        $choice = new DeferredFuture();
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn) use ($choice): null {
                $choice->complete($turn->requestPermission('call_1', 'rm -rf build'));

                return null;
            })
            ->connect();
        $client->initialize();

        $client->newSession(cwd: '/tmp')->prompt('clean')->wait();

        self::assertSame('reject', $choice->getFuture()->await());
    }

    public function test_a_permission_handler_decides(): void
    {
        $choices = [];
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn) use (&$choices): null {
                $choices[] = $turn->requestPermission('call_1', 'npm test');
                $choices[] = $turn->requestPermission('call_2', 'rm -rf /', ToolKind::Delete);

                return null;
            })
            ->connect(permissions: new CallbackPermissions(
                fn (RequestPermissionRequest $request): RequestPermissionResponse => $request->toolCall->kind === ToolKind::Delete
                    ? Outcome::reject($request)
                    : Outcome::approve($request),
            ));
        $client->initialize();

        $client->newSession(cwd: '/tmp')->prompt('go')->wait();

        self::assertSame(['allow', 'reject'], $choices);
    }

    public function test_cancelling_a_turn_answers_pending_permissions_with_cancelled(): void
    {
        $asked = new DeferredFuture();
        $agentSaw = new DeferredFuture();
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn) use ($agentSaw): ?StopReason {
                $choice = $turn->requestPermission('call_1', 'deploy');
                $agentSaw->complete($choice);

                return $choice === null ? StopReason::Cancelled : null;
            })
            ->connect(permissions: new CallbackPermissions(
                function (RequestPermissionRequest $request, Cancellation $cancellation) use ($asked): RequestPermissionResponse {
                    $asked->complete();
                    // A human who never answers.
                    (new DeferredFuture())->getFuture()->await();

                    return Outcome::approve($request);
                },
            ));
        $client->initialize();

        $turn = $client->newSession(cwd: '/tmp')->prompt('deploy');
        $asked->getFuture()->await();
        $turn->cancel();

        self::assertNull($agentSaw->getFuture()->await());
        self::assertSame(StopReason::Cancelled, $turn->stopReason());
    }

    public function test_a_prompt_cancellation_cancels_the_turn(): void
    {
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn): null {
                $turn->say('Working…');
                $turn->untilCancelled();

                return null;
            })
            ->connect();
        $client->initialize();

        $cancellation = new DeferredCancellation();
        $turn = $client->newSession(cwd: '/tmp')->prompt('long task', $cancellation->getCancellation());
        async(function () use ($cancellation): void {
            delay(0.01);
            $cancellation->cancel();
        })->ignore();

        self::assertSame(StopReason::Cancelled, $turn->stopReason());
        self::assertSame('Working…', $turn->text());
    }

    public function test_one_turn_at_a_time(): void
    {
        $release = new DeferredFuture();
        $client = FakeAgent::create()
            ->onPrompt(function () use ($release): null {
                $release->getFuture()->await();

                return null;
            })
            ->connect();
        $client->initialize();
        $session = $client->newSession(cwd: '/tmp');

        $first = $session->prompt('one');
        try {
            $session->prompt('two');
            self::fail('A second turn should have been refused.');
        } catch (TurnInProgress) {
        }

        $release->complete();
        $first->wait();
        self::assertSame(StopReason::EndTurn, $session->prompt('two')->stopReason());
    }

    public function test_the_agent_reads_and_writes_files_through_the_client(): void
    {
        $root = sys_get_temp_dir() . '/acp-fs-' . bin2hex(random_bytes(4));
        mkdir($root);
        file_put_contents($root . '/style.css', "body {\n  background: #fff;\n}\n");
        $results = [];

        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn) use ($root, &$results): null {
                $results['line'] = $turn->readFile($root . '/style.css', line: 2, limit: 1);
                $turn->writeFile($root . '/dist/style.css', 'body { background: #111; }');
                try {
                    $turn->readFile($root . '/../etc/passwd');
                } catch (RpcError $e) {
                    $results['escape'] = $e->getMessage();
                }

                return null;
            })
            ->connect(fileSystem: new LocalFileSystem($root));
        $client->initialize();

        $client->newSession(cwd: $root)->prompt('darken')->wait();

        self::assertSame("  background: #fff;\n", $results['line']);
        self::assertSame('body { background: #111; }', file_get_contents($root . '/dist/style.css'));
        self::assertStringContainsString('Access outside of', $results['escape']);
    }

    public function test_capabilities_follow_the_handlers(): void
    {
        $agent = FakeAgent::create();
        $agent->connect()->initialize();
        $agent->connect(fileSystem: new LocalFileSystem(sys_get_temp_dir()))->initialize();

        [$without, $with] = $agent->received('initialize');
        self::assertFalse($without['clientCapabilities']['fs']['readTextFile'] ?? false);
        self::assertTrue($with['clientCapabilities']['fs']['readTextFile']);
        self::assertTrue($with['clientCapabilities']['fs']['writeTextFile']);
    }

    public function test_a_session_can_be_loaded_with_its_history(): void
    {
        $agent = FakeAgent::create()->onPrompt(function (FakeTurn $turn): null {
            $turn->say('Hello!');

            return null;
        });

        $first = $agent->connect();
        $first->initialize();
        $session = $first->newSession(cwd: '/tmp');
        $session->prompt('Hi')->wait();

        $second = $agent->connect();
        $second->initialize();
        $loaded = $second->loadSession($session->id, cwd: '/tmp');

        $history = $loaded->history();
        self::assertCount(2, $history);
        self::assertInstanceOf(SessionUpdate\UserMessageChunk::class, $history[0]);
        self::assertInstanceOf(SessionUpdate\AgentMessageChunk::class, $history[1]);
        self::assertSame('code', $loaded->modes()?->currentModeId);
        self::assertSame('Hello!', $loaded->prompt('Again')->text());
    }

    public function test_loading_requires_the_capability(): void
    {
        $client = FakeAgent::create()->withCapabilities(new AgentCapabilities(loadSession: false))->connect();
        $client->initialize();

        $this->expectException(CapabilityNotSupported::class);
        $client->loadSession('sess_1', cwd: '/tmp');
    }

    public function test_authentication_when_the_agent_requires_it(): void
    {
        $client = FakeAgent::create()->requiringAuthentication()->connect();
        $init = $client->initialize();

        try {
            $client->newSession(cwd: '/tmp');
            self::fail('Authentication should have been required.');
        } catch (RpcError $e) {
            self::assertTrue($e->isAuthRequired());
        }

        $client->authenticate($init->authMethods[0] instanceof \Acp\Schema\AuthMethod\Agent ? $init->authMethods[0]->id : '');
        self::assertStringStartsWith('fake-session-', $client->newSession(cwd: '/tmp')->id);
    }

    public function test_an_unsupported_protocol_version_closes_the_connection(): void
    {
        $client = FakeAgent::create()->speakingProtocol(0)->connect();

        try {
            $client->initialize();
            self::fail('The version mismatch should have been detected.');
        } catch (UnsupportedProtocolVersion) {
        }

        self::assertTrue($client->isClosed());
    }

    public function test_sessions_require_initialize(): void
    {
        $this->expectException(CapabilityNotSupported::class);
        FakeAgent::create()->connect()->newSession(cwd: '/tmp');
    }

    public function test_an_agent_error_fails_the_turn(): void
    {
        $client = FakeAgent::create()
            ->onPrompt(fn () => throw new RpcError(-32603, 'Model overloaded'))
            ->connect();
        $client->initialize();

        $turn = $client->newSession(cwd: '/tmp')->prompt('hi');

        $this->expectException(RpcError::class);
        $this->expectExceptionMessage('Model overloaded');
        $turn->wait();
    }

    public function test_updates_outside_turns_reach_listeners(): void
    {
        $client = FakeAgent::create()->connect();
        $client->initialize();
        $session = $client->newSession(cwd: '/tmp');
        $seen = [];
        $session->onUpdate(function (SessionUpdate $update) use (&$seen): void {
            $seen[] = $update::class;
        });

        $session->setMode('ask');

        self::assertSame([SessionUpdate\CurrentModeUpdate::class], $seen);
        self::assertSame('ask', $session->modes()?->currentModeId);
    }

    public function test_a_closed_connection_fails_the_running_turn(): void
    {
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn): null {
                $turn->untilCancelled();

                return null;
            })
            ->connect();
        $client->initialize();
        $turn = $client->newSession(cwd: '/tmp')->prompt('hi');

        $client->close();

        $this->expectException(\Acp\Exception\ConnectionClosed::class);
        $turn->wait();
    }
}
