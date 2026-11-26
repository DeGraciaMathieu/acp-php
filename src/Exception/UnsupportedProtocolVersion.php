<?php

declare(strict_types=1);

namespace Acp\Exception;

use RuntimeException;

/**
 * The agent answered initialize with a protocol version this SDK does not speak.
 */
final class UnsupportedProtocolVersion extends RuntimeException implements AcpException
{
    public static function fromAgent(int $agentVersion, int $clientVersion): self
    {
        return new self(sprintf('The agent speaks ACP v%d, this SDK speaks v%d.', $agentVersion, $clientVersion));
    }
}
