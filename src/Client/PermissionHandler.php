<?php

declare(strict_types=1);

namespace Acp\Client;

use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;
use Amp\Cancellation;

/**
 * Decides on the agent's permission requests (running a command, editing a file…).
 *
 * The handler may take its time, for instance to ask a human: the rest of the session
 * keeps flowing meanwhile. When the turn is cancelled, $cancellation fires and the SDK
 * answers with the "cancelled" outcome, as the protocol requires.
 */
interface PermissionHandler
{
    public function requestPermission(RequestPermissionRequest $request, Cancellation $cancellation): RequestPermissionResponse;
}
