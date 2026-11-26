<?php

declare(strict_types=1);

namespace Acp\Client\Permission;

use Acp\Client\PermissionHandler;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;
use Amp\Cancellation;
use Closure;

/**
 * Delegates to a closure, handy for policies:
 *
 *     new CallbackPermissions(fn (RequestPermissionRequest $r) => $r->toolCall->kind === ToolKind::Edit
 *         ? Outcome::approve($r)
 *         : Outcome::reject($r));
 */
final class CallbackPermissions implements PermissionHandler
{
    /**
     * @param Closure(RequestPermissionRequest, Cancellation): RequestPermissionResponse $callback
     */
    public function __construct(
        private readonly Closure $callback,
    ) {
    }

    public function requestPermission(RequestPermissionRequest $request, Cancellation $cancellation): RequestPermissionResponse
    {
        return ($this->callback)($request, $cancellation);
    }
}
