<?php

declare(strict_types=1);

namespace Acp\Rpc;

use Acp\Exception\ConnectionClosed;
use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Iterator;

use function Amp\ByteStream\splitLines;

/**
 * The stdio transport of ACP: messages are read from one stream and written to another,
 * one JSON document per line.
 *
 * Used by the client on the agent's stdout/stdin, and by an agent on its own stdin/stdout.
 */
final class StdioTransport implements Transport
{
    /** @var Iterator<int, string>|null */
    private ?Iterator $lines = null;

    public function __construct(
        private readonly ReadableStream $input,
        private readonly WritableStream $output,
    ) {
    }

    public function receive(?Cancellation $cancellation = null): ?string
    {
        if ($this->lines === null) {
            $lines = splitLines($this->input, $cancellation);
            $this->lines = $lines instanceof Iterator ? $lines : new \IteratorIterator($lines);
            $this->lines->rewind();
        } else {
            $this->lines->next();
        }

        while ($this->lines->valid()) {
            $line = trim((string) $this->lines->current());
            if ($line !== '') {
                return $line;
            }
            $this->lines->next();
        }

        return null;
    }

    public function send(string $message): void
    {
        try {
            $this->output->write($message . "\n");
        } catch (ClosedException|StreamException $e) {
            throw ConnectionClosed::whileSending($e);
        }
    }

    public function close(): void
    {
        $this->output->close();
        $this->input->close();
    }
}
