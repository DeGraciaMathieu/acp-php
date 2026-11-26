<?php

declare(strict_types=1);

namespace Acp\Client\Permission;

use Acp\Client\PermissionHandler;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;
use Amp\Cancellation;

/**
 * Rejects every request. The default when no handler is given.
 */
final class RejectAll implements PermissionHandler
{
    public function requestPermission(RequestPermissionRequest $request, Cancellation $cancellation): RequestPermissionResponse
    {
        return Outcome::reject($request);
    }
}
