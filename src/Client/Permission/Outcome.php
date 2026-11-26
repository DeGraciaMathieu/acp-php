<?php

declare(strict_types=1);

namespace Acp\Client\Permission;

use Acp\Schema\PermissionOption;
use Acp\Schema\PermissionOptionKind;
use Acp\Schema\RequestPermissionOutcome\Cancelled;
use Acp\Schema\RequestPermissionOutcome\Selected;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;

/**
 * Builds permission responses.
 */
final class Outcome
{
    /**
     * Selects the first option of the first kind offered by the agent, in the given order of preference.
     * Falls back to a reject option, then to "cancelled" when the agent offers none of them.
     */
    public static function select(RequestPermissionRequest $request, PermissionOptionKind ...$kinds): RequestPermissionResponse
    {
        foreach ([...$kinds, PermissionOptionKind::RejectOnce, PermissionOptionKind::RejectAlways] as $kind) {
            $option = self::find($request, $kind);
            if ($option !== null) {
                return self::option($option->optionId);
            }
        }

        return self::cancelled();
    }

    public static function approve(RequestPermissionRequest $request): RequestPermissionResponse
    {
        return self::select($request, PermissionOptionKind::AllowOnce, PermissionOptionKind::AllowAlways);
    }

    public static function reject(RequestPermissionRequest $request): RequestPermissionResponse
    {
        return self::select($request, PermissionOptionKind::RejectOnce, PermissionOptionKind::RejectAlways);
    }

    public static function option(string $optionId): RequestPermissionResponse
    {
        return new RequestPermissionResponse(new Selected($optionId));
    }

    public static function cancelled(): RequestPermissionResponse
    {
        return new RequestPermissionResponse(new Cancelled());
    }

    private static function find(RequestPermissionRequest $request, PermissionOptionKind $kind): ?PermissionOption
    {
        foreach ($request->options as $option) {
            if ($option->kind === $kind) {
                return $option;
            }
        }

        return null;
    }
}
