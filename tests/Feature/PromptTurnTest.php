<?php

declare(strict_types=1);

namespace Acp\Tests\Feature;

use Acp\Exception\RpcError;
use Acp\Exception\TurnInProgress;
use Acp\Schema\ContentBlock\TextContent;
use Acp\Schema\SessionUpdate;
use Acp\Schema\StopReason;
use Acp\Schema\ToolCallStatus;
use Acp\Schema\ToolKind;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;
use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PromptTurnTest extends TestCase
{
    #[Test]
    public function a_full_prompt_turn(): void
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
        $client->initialize();

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

    #[Test]
    public function waiting_for_the_text_does_not_require_reading_updates(): void
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

    #[Test]
    public function agent_thoughts_are_streamed_but_kept_out_of_the_text(): void
    {
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn): null {
                $turn->think('The user wants a greeting.');
                $turn->say('Hello!');

                return null;
            })
            ->connect();
        $client->initialize();

        $turn = $client->newSession(cwd: '/tmp')->prompt('hi');
        $updates = iterator_to_array($turn->updates(), false);

        self::assertInstanceOf(SessionUpdate\AgentThoughtChunk::class, $updates[0]);
        self::assertSame('Hello!', $turn->text());
    }

    #[Test]
    public function updates_can_only_be_read_once(): void
    {
        $client = FakeAgent::create()->connect();
        $client->initialize();
        $turn = $client->newSession(cwd: '/tmp')->prompt('hi');
        iterator_to_array($turn->updates(), false);

        $this->expectException(\LogicException::class);
        iterator_to_array($turn->updates(), false);
    }

    #[Test]
    public function one_turn_at_a_time(): void
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

    #[Test]
    public function an_agent_error_fails_the_turn(): void
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
}
