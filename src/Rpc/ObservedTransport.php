<?php

declare(strict_types=1);

namespace Acp\Rpc;

use Amp\Cancellation;
use Closure;

/**
 * Hands every raw message to an observer before passing it through.
 */
final class ObservedTransport implements Transport
{
    /**
     * @param Closure(Direction, string): void $observer
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly Closure $observer,
    ) {
    }

    public function receive(?Cancellation $cancellation = null): ?string
    {
        $message = $this->transport->receive($cancellation);
        if ($message !== null) {
            ($this->observer)(Direction::Received, $message);
        }

        return $message;
    }

    public function send(string $message): void
    {
        ($this->observer)(Direction::Sent, $message);
        $this->transport->send($message);
    }

    public function close(): void
    {
        $this->transport->close();
    }
}
