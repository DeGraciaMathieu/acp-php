<?php

declare(strict_types=1);

namespace Acp\Client;

use Acp\Schema\SessionUpdate;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Generator;
use SplQueue;
use Throwable;

/**
 * Unbounded buffer of session updates: the agent is never slowed down by a consumer
 * that reads late, or not at all.
 *
 * @internal
 */
final class UpdateStream
{
    /** @var SplQueue<SessionUpdate> */
    private SplQueue $buffer;

    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $waiting = null;

    private bool $complete = false;

    private ?Throwable $error = null;

    private bool $consumed = false;

    public function __construct()
    {
        $this->buffer = new SplQueue();
    }

    public function push(SessionUpdate $update): void
    {
        if ($this->complete) {
            return;
        }
        $this->buffer->enqueue($update);
        $this->wake();
    }

    public function complete(?Throwable $error = null): void
    {
        $this->complete = true;
        $this->error = $error;
        $this->wake();
    }

    /**
     * Yields updates as they arrive, until the stream completes. Can only be iterated once.
     *
     * @return Generator<int, SessionUpdate>
     */
    public function iterate(?Cancellation $cancellation = null): Generator
    {
        if ($this->consumed) {
            throw new \LogicException('Updates can only be iterated once.');
        }
        $this->consumed = true;

        while (true) {
            while (! $this->buffer->isEmpty()) {
                yield $this->buffer->dequeue();
            }
            if ($this->complete) {
                if ($this->error !== null) {
                    throw $this->error;
                }

                return;
            }
            $this->waiting = new DeferredFuture();
            $this->waiting->getFuture()->await($cancellation);
        }
    }

    private function wake(): void
    {
        $waiting = $this->waiting;
        $this->waiting = null;
        $waiting?->complete();
    }
}
