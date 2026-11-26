<?php

declare(strict_types=1);

namespace Acp\Exception;

use LogicException;

/**
 * ACP v1 runs one prompt turn at a time per session.
 */
final class TurnInProgress extends LogicException implements AcpException
{
    public static function in(string $sessionId): self
    {
        return new self(sprintf('Session "%s" already has a prompt turn in progress: wait for it or cancel it first.', $sessionId));
    }
}
