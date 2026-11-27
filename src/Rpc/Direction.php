<?php

declare(strict_types=1);

namespace Acp\Rpc;

/**
 * Which way a raw message travels, seen from the local peer.
 */
enum Direction: string
{
    case Sent = '→';
    case Received = '←';
}
