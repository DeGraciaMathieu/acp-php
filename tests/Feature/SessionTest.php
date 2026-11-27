<?php

declare(strict_types=1);

namespace Acp\Tests\Feature;

use Acp\Exception\CapabilityNotSupported;
use Acp\Schema\AgentCapabilities;
use Acp\Schema\SessionUpdate;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;
use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    #[Test]
    public function a_session_can_be_loaded_with_its_history(): void
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

    #[Test]
    public function a_session_works_in_the_current_directory_unless_told_otherwise(): void
    {
        $agent = FakeAgent::create();
        $client = $agent->connect();
        $client->initialize();

        $client->newSession();
        $client->newSession(cwd: '/srv/other-project');

        self::assertSame([getcwd(), '/srv/other-project'], array_column($agent->received('session/new'), 'cwd'));
    }

    #[Test]
    public function loading_requires_the_capability(): void
    {
        $client = FakeAgent::create()->withCapabilities(new AgentCapabilities(loadSession: false))->connect();
        $client->initialize();

        $this->expectException(CapabilityNotSupported::class);
        $client->loadSession('sess_1', cwd: '/tmp');
    }

    #[Test]
    public function a_host_finds_a_session_and_its_running_turn(): void
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

        $turn = $session->prompt('long task');
        self::assertSame($session, $client->session($session->id));
        self::assertSame($turn, $session->currentTurn());

        $release->complete();
        $turn->wait();
        self::assertNull($session->currentTurn());
        self::assertNull($client->session('unknown'));
    }

    #[Test]
    public function updates_outside_turns_reach_listeners(): void
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
}
