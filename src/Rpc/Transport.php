<?php

declare(strict_types=1);

namespace Acp\Rpc;

use Amp\Cancellation;

/**
 * Carries newline-delimited JSON-RPC messages between two peers.
 *
 * A message is one line of UTF-8 JSON, without embedded newline.
 */
interface Transport
{
    /**
     * Next raw message, or null once the peer closed the stream.
     */
    public function receive(?Cancellation $cancellation = null): ?string;

    public function send(string $message): void;

    public function close(): void;
}
