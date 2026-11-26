<?php

declare(strict_types=1);

namespace Acp\Rpc;

use Acp\Exception\ConnectionClosed;
use Amp\Cancellation;
use Amp\DeferredFuture;
use SplQueue;

/**
 * Two transports wired to each other, for tests and in-process agents.
 */
final class InMemoryTransport implements Transport
{
    /** @var SplQueue<string> */
    private SplQueue $inbox;

    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $waiting = null;

    private bool $closed = false;

    private ?self $peer = null;

    private function __construct()
    {
        $this->inbox = new SplQueue();
    }

    /**
     * @return array{0: self, 1: self}
     */
    public static function pair(): array
    {
        $a = new self();
        $b = new self();
        $a->peer = $b;
        $b->peer = $a;

        return [$a, $b];
    }

    public function receive(?Cancellation $cancellation = null): ?string
    {
        while ($this->inbox->isEmpty()) {
            if ($this->closed) {
                return null;
            }
            $this->waiting = new DeferredFuture();
            $this->waiting->getFuture()->await($cancellation);
        }

        return $this->inbox->dequeue();
    }

    public function send(string $message): void
    {
        if ($this->closed || $this->peer === null || $this->peer->closed) {
            throw ConnectionClosed::whileSending();
        }

        $this->peer->deliver($message);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->wake();
        $this->peer?->close();
    }

    private function deliver(string $message): void
    {
        $this->inbox->enqueue($message);
        $this->wake();
    }

    private function wake(): void
    {
        $waiting = $this->waiting;
        $this->waiting = null;
        $waiting?->complete();
    }
}
