<?php

declare(strict_types=1);

namespace Acp\Exception;

use LogicException;

/**
 * The agent did not advertise the capability a call relies on.
 */
final class CapabilityNotSupported extends LogicException implements AcpException
{
    public static function named(string $capability): self
    {
        return new self(sprintf('The agent does not advertise the "%s" capability.', $capability));
    }

    public static function notInitialized(): self
    {
        return new self('Call initialize() before using the connection.');
    }
}
