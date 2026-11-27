<?php

declare(strict_types=1);

namespace Acp\Tests\Feature;

use Acp\Client\Permission\ApproveAll;
use Acp\Client\Permission\CallbackPermissions;
use Acp\Client\Permission\Outcome;
use Acp\Client\PermissionHandler;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;
use Acp\Schema\ToolKind;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PermissionsTest extends TestCase
{
    #[Test]
    public function permissions_are_rejected_by_default(): void
    {
        self::assertSame(['reject'], $this->choicesFor(null));
    }

    #[Test]
    public function every_request_can_be_approved_once(): void
    {
        self::assertSame(['allow'], $this->choicesFor(new ApproveAll()));
    }

    #[Test]
    public function a_permission_handler_decides(): void
    {
        $choices = [];
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn) use (&$choices): null {
                $choices[] = $turn->requestPermission('call_1', 'npm test');
                $choices[] = $turn->requestPermission('call_2', 'rm -rf /', ToolKind::Delete);

                return null;
            })
            ->connect(permissions: new CallbackPermissions(
                fn (RequestPermissionRequest $request): RequestPermissionResponse => $request->toolCall->kind === ToolKind::Delete
                    ? Outcome::reject($request)
                    : Outcome::approve($request),
            ));
        $client->initialize();

        $client->newSession(cwd: '/tmp')->prompt('go')->wait();

        self::assertSame(['allow', 'reject'], $choices);
    }

    /**
     * @return list<string|null> Options selected for a single "rm -rf build" request
     */
    private function choicesFor(?PermissionHandler $permissions): array
    {
        $choices = [];
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn) use (&$choices): null {
                $choices[] = $turn->requestPermission('call_1', 'rm -rf build');

                return null;
            })
            ->connect(permissions: $permissions);
        $client->initialize();

        $client->newSession(cwd: '/tmp')->prompt('clean')->wait();

        return $choices;
    }
}
