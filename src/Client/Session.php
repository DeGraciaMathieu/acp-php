<?php

declare(strict_types=1);

namespace Acp\Client;

use Acp\Exception\TurnInProgress;
use Acp\Rpc\Connection;
use Acp\Schema\AgentMethod;
use Acp\Schema\CancelNotification;
use Acp\Schema\ContentBlock;
use Acp\Schema\ContentBlock\TextContent;
use Acp\Schema\PromptRequest;
use Acp\Schema\PromptResponse;
use Acp\Schema\SessionModeState;
use Acp\Schema\SessionUpdate;
use Acp\Schema\SetSessionModeRequest;
use Acp\Support\Cast;
use Amp\Cancellation;
use Closure;
use Throwable;

use function Amp\async;

/**
 * A conversation with the agent, with its own context and history.
 */
final class Session
{
    private ?Turn $turn = null;

    /** @var list<Closure(SessionUpdate): void> */
    private array $listeners = [];

    /** @var list<SessionUpdate> */
    private array $history = [];

    private bool $recordingHistory = false;

    /**
     * @internal created by ClientConnection
     */
    public function __construct(
        public readonly string $id,
        private readonly Connection $rpc,
        private ?SessionModeState $modes = null,
    ) {
    }

    /**
     * Modes offered by the agent (ask, code, architect…) and the current one, if it has modes.
     */
    public function modes(): ?SessionModeState
    {
        return $this->modes;
    }

    /**
     * Sends a prompt and starts a turn. Returns immediately: read the turn's updates,
     * or wait for its end. Firing $cancellation cancels the turn.
     *
     * @param string|ContentBlock|list<ContentBlock> $prompt
     *
     * @throws TurnInProgress when a turn is still running in this session
     */
    public function prompt(string|ContentBlock|array $prompt, ?Cancellation $cancellation = null): Turn
    {
        if ($this->turn !== null && ! $this->turn->isDone()) {
            throw TurnInProgress::in($this->id);
        }

        $blocks = match (true) {
            is_string($prompt) => [new TextContent($prompt)],
            $prompt instanceof ContentBlock => [$prompt],
            default => $prompt,
        };

        $turn = new Turn($this);
        $this->turn = $turn;

        // A fired cancellation cancels the turn the ACP way (session/cancel): the agent
        // then ends it with the "cancelled" stop reason.
        $subscription = $cancellation?->subscribe(fn () => $turn->cancel());

        async(function () use ($turn, $blocks, $cancellation, $subscription): void {
            try {
                $result = $this->rpc->request(
                    AgentMethod::SessionPrompt->value,
                    new PromptRequest(sessionId: $this->id, prompt: $blocks),
                );
                $turn->finish(PromptResponse::fromArray(Cast::obj($result, 'result')));
            } catch (Throwable $e) {
                $turn->fail($e);
            } finally {
                if ($cancellation !== null && $subscription !== null) {
                    $cancellation->unsubscribe($subscription);
                }
            }
        })->ignore();

        return $turn;
    }

    /**
     * Cancels the current turn, if any. See Turn::cancel().
     */
    public function cancel(): void
    {
        $this->turn?->markCancelled();
        $this->rpc->notify(AgentMethod::SessionCancel->value, new CancelNotification($this->id));
    }

    public function currentTurn(): ?Turn
    {
        return $this->turn !== null && ! $this->turn->isDone() ? $this->turn : null;
    }

    public function setMode(string $modeId, ?Cancellation $cancellation = null): void
    {
        $this->rpc->request(
            AgentMethod::SessionSetMode->value,
            new SetSessionModeRequest(sessionId: $this->id, modeId: $modeId),
            $cancellation,
        );

        // Agents are not required to confirm with a current_mode_update notification.
        if ($this->modes !== null) {
            $this->modes = new SessionModeState($modeId, $this->modes->availableModes, $this->modes->meta);
        }
    }

    /**
     * Listens to every update of this session, inside turns or not (mode changes, available
     * commands, history replayed by loadSession…). Handy to log or persist a session.
     *
     * @param Closure(SessionUpdate): void $listener
     */
    public function onUpdate(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Updates replayed by the agent when the session was loaded.
     *
     * @return list<SessionUpdate>
     */
    public function history(): array
    {
        return $this->history;
    }

    /**
     * @internal
     */
    public function dispatch(SessionUpdate $update): void
    {
        if ($update instanceof SessionUpdate\CurrentModeUpdate && $this->modes !== null) {
            $this->modes = new SessionModeState($update->currentModeId, $this->modes->availableModes, $this->modes->meta);
        }

        if ($this->recordingHistory) {
            $this->history[] = $update;
        } elseif ($this->turn !== null && ! $this->turn->isDone()) {
            $this->turn->receive($update);
        }

        foreach ($this->listeners as $listener) {
            $listener($update);
        }
    }

    /**
     * @internal
     */
    public function setModes(?SessionModeState $modes): void
    {
        $this->modes = $modes;
    }

    /**
     * @internal
     */
    public function recordHistory(bool $recording): void
    {
        $this->recordingHistory = $recording;
    }

    /**
     * @internal
     */
    public function turnCancellation(): ?Cancellation
    {
        return $this->turn !== null && ! $this->turn->isDone() ? $this->turn->cancellation() : null;
    }

    /**
     * @internal
     */
    public function abort(Throwable $error): void
    {
        if ($this->turn !== null && ! $this->turn->isDone()) {
            $this->turn->fail($error);
        }
    }
}
