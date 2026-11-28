<?php

declare(strict_types=1);

namespace Acp\Client\Permission;

use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;
use Amp\Cancellation;

/**
 * Approves every request, once at a time ("allow once" is preferred over "allow always").
 *
 * Only for sandboxes: the agent may then run any command.
 */
final class ApproveAll implements PermissionHandler
{
    public function requestPermission(RequestPermissionRequest $request, Cancellation $cancellation): RequestPermissionResponse
    {
        return Outcome::approve($request);
    }
}
