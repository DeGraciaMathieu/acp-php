<?php

declare(strict_types=1);

namespace Acp\Client;

use Acp\Schema\ContentBlock\TextContent;
use Acp\Schema\PromptResponse;
use Acp\Schema\SessionUpdate;
use Acp\Schema\StopReason;
use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Generator;
use Throwable;

/**
 * One prompt turn: the updates streamed by the agent, then its stop reason.
 *
 *     $turn = $session->prompt('Switch to a dark theme');
 *     foreach ($turn->updates() as $update) { … }
 *     $turn->stopReason(); // StopReason::EndTurn
 *
 * Updates are buffered: reading them is optional, and waiting for the end with
 * stopReason() or text() works without iterating.
 */
final class Turn
{
    private readonly UpdateStream $stream;

    /** @var DeferredFuture<PromptResponse> */
    private readonly DeferredFuture $response;

    private readonly DeferredCancellation $cancellation;

    private string $text = '';

    /**
     * @internal created by Session::prompt()
     */
    public function __construct(
        private readonly Session $session,
    ) {
        $this->stream = new UpdateStream();
        $this->response = new DeferredFuture();
        $this->cancellation = new DeferredCancellation();
    }

    /**
     * Updates of this turn, as they arrive. Ends when the agent ends the turn.
     *
     * @return Generator<int, SessionUpdate>
     */
    public function updates(?Cancellation $cancellation = null): Generator
    {
        return $this->stream->iterate($cancellation);
    }

    /**
     * Waits for the end of the turn.
     */
    public function wait(?Cancellation $cancellation = null): PromptResponse
    {
        return $this->response->getFuture()->await($cancellation);
    }

    public function stopReason(?Cancellation $cancellation = null): StopReason|string
    {
        return $this->wait($cancellation)->stopReason;
    }

    /**
     * Waits for the end of the turn and returns the agent's message, as plain text.
     */
    public function text(?Cancellation $cancellation = null): string
    {
        $this->wait($cancellation);

        return $this->text;
    }

    public function isDone(): bool
    {
        return $this->response->isComplete();
    }

    /**
     * Asks the agent to stop. The turn still ends normally, with the "cancelled" stop reason,
     * and pending permission requests are answered with the "cancelled" outcome.
     */
    public function cancel(): void
    {
        if (! $this->isDone()) {
            $this->session->cancel();
        }
    }

    /**
     * @internal
     */
    public function cancellation(): Cancellation
    {
        return $this->cancellation->getCancellation();
    }

    /**
     * @internal
     */
    public function markCancelled(): void
    {
        if (! $this->cancellation->isCancelled()) {
            $this->cancellation->cancel();
        }
    }

    /**
     * @internal
     */
    public function receive(SessionUpdate $update): void
    {
        if ($update instanceof SessionUpdate\AgentMessageChunk && $update->content instanceof TextContent) {
            $this->text .= $update->content->text;
        }
        $this->stream->push($update);
    }

    /**
     * @internal
     */
    public function finish(PromptResponse $response): void
    {
        if ($this->response->isComplete()) {
            return;
        }
        $this->stream->complete();
        $this->response->complete($response);
    }

    /**
     * @internal
     */
    public function fail(Throwable $error): void
    {
        if ($this->response->isComplete()) {
            return;
        }
        $this->stream->complete($error);
        $this->response->error($error);
        // The failure is reported through wait(); do not let it surface as unhandled.
        $this->response->getFuture()->ignore();
    }
}
