<?php

declare(strict_types=1);

namespace Acp\Tests\Feature;

use Acp\Client\Permission\ApproveAll;
use Acp\Client\Permission\CallbackPermissions;
use Acp\Client\Permission\Outcome;
use Acp\Client\Permission\PermissionHandler;
use Acp\Exception\RpcError;
use Acp\Schema\ErrorCode;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;
use Acp\Schema\SessionUpdate;
use Acp\Schema\ToolKind;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Amp\async;

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

    #[Test]
    public function updates_keep_flowing_while_a_permission_is_pending(): void
    {
        $progress = new DeferredFuture();
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn): null {
                $pending = async(fn () => $turn->requestPermission('call_1', 'deploy'));
                $turn->say('Preparing the release… ');
                $turn->say('Permission: ' . $pending->await());

                return null;
            })
            ->connect(permissions: new CallbackPermissions(
                function (RequestPermissionRequest $request) use ($progress): RequestPermissionResponse {
                    // A human who answers only after seeing the agent's progress.
                    $progress->getFuture()->await(new TimeoutCancellation(1.0));

                    return Outcome::approve($request);
                },
            ));
        $client->initialize();
        $session = $client->newSession(cwd: '/tmp');
        $session->onUpdate(function (SessionUpdate $update) use ($progress): void {
            if ($update instanceof SessionUpdate\AgentMessageChunk && ! $progress->isComplete()) {
                $progress->complete();
            }
        });

        self::assertSame('Preparing the release… Permission: allow', $session->prompt('release')->text());
    }

    #[Test]
    public function a_failing_permission_handler_is_reported_to_both_sides(): void
    {
        $agentError = null;
        $hostErrors = [];
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn) use (&$agentError): null {
                try {
                    $turn->requestPermission('call_1', 'deploy');
                } catch (RpcError $e) {
                    $agentError = $e;
                }

                return null;
            })
            ->connect(
                permissions: new CallbackPermissions(fn () => throw new \RuntimeException('Approval service down')),
                onError: function (\Throwable $e) use (&$hostErrors): void {
                    $hostErrors[] = $e->getMessage();
                },
            );
        $client->initialize();

        $client->newSession(cwd: '/tmp')->prompt('deploy')->wait();

        self::assertInstanceOf(RpcError::class, $agentError);
        self::assertSame(ErrorCode::InternalError, $agentError->errorCode());
        self::assertSame(['Approval service down'], $hostErrors);
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
