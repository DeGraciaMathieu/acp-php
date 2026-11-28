<?php

declare(strict_types=1);

namespace Acp\Client\Session;

/**
 * What a turn needs from the session that started it.
 *
 * @internal implemented by Session
 */
interface TurnOwner
{
    /**
     * Cancels the current turn, see Turn::cancel().
     */
    public function cancel(): void;
}
