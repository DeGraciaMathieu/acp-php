<?php

declare(strict_types=1);

namespace Acp\Exception;

use RuntimeException;
use Throwable;

/**
 * The connection with the peer is gone (process exited, stream closed).
 */
final class ConnectionClosed extends RuntimeException implements AcpException
{
    public static function whileSending(?Throwable $previous = null): self
    {
        return new self('Cannot send: the ACP connection is closed.', 0, $previous);
    }

    public static function whileWaiting(string $method): self
    {
        return new self(sprintf('The ACP connection closed before "%s" got a response.', $method));
    }
}
