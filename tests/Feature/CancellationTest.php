<?php

declare(strict_types=1);

namespace Acp\Tests\Feature;

use Acp\Client\ClientConnection;
use Acp\Client\Permission\CallbackPermissions;
use Acp\Client\Permission\Outcome;
use Acp\Rpc\Connection;
use Acp\Rpc\InMemoryTransport;
use Acp\Schema\ClientMethod;
use Acp\Schema\PermissionOption;
use Acp\Schema\PermissionOptionKind;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;
use Acp\Schema\StopReason;
use Acp\Schema\ToolCallUpdate;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class CancellationTest extends TestCase
{
    #[Test]
    public function cancelling_a_turn_answers_pending_permissions_with_cancelled(): void
    {
        $asked = new DeferredFuture();
        $agentSaw = new DeferredFuture();
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn) use ($agentSaw): ?StopReason {
                $choice = $turn->requestPermission('call_1', 'deploy');
                $agentSaw->complete($choice);

                return $choice === null ? StopReason::Cancelled : null;
            })
            ->connect(permissions: new CallbackPermissions(
                function (RequestPermissionRequest $request, Cancellation $cancellation) use ($asked): RequestPermissionResponse {
                    $asked->complete();
                    // A human who never answers.
                    (new DeferredFuture())->getFuture()->await();

                    return Outcome::approve($request);
                },
            ));
        $client->initialize();

        $turn = $client->newSession(cwd: '/tmp')->prompt('deploy');
        $asked->getFuture()->await();
        $turn->cancel();

        self::assertNull($agentSaw->getFuture()->await());
        self::assertSame(StopReason::Cancelled, $turn->stopReason());
    }

    #[Test]
    public function a_prompt_cancellation_cancels_the_turn(): void
    {
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn): null {
                $turn->say('Working…');
                $turn->untilCancelled();

                return null;
            })
            ->connect();
        $client->initialize();

        $cancellation = new DeferredCancellation();
        $turn = $client->newSession(cwd: '/tmp')->prompt('long task', $cancellation->getCancellation());
        async(function () use ($cancellation): void {
            delay(0.01);
            $cancellation->cancel();
        })->ignore();

        self::assertSame(StopReason::Cancelled, $turn->stopReason());
        self::assertSame('Working…', $turn->text());
    }

    #[Test]
    public function an_agent_can_withdraw_a_permission_request(): void
    {
        $withdrawn = new DeferredFuture();
        [$clientSide, $agentSide] = InMemoryTransport::pair();
        new ClientConnection($clientSide, permissions: new CallbackPermissions(
            function (RequestPermissionRequest $request, Cancellation $cancellation) use ($withdrawn): RequestPermissionResponse {
                try {
                    // A human who has not answered yet.
                    (new DeferredFuture())->getFuture()->await($cancellation);
                } catch (CancelledException) {
                    $withdrawn->complete(true);
                }

                return Outcome::cancelled();
            },
        ));
        $agent = new Connection($agentSide, fn () => null, fn () => null);
        $agent->start();

        $cancellation = new DeferredCancellation();
        async(function () use ($cancellation): void {
            delay(0.01);
            $cancellation->cancel();
        })->ignore();
        try {
            $agent->request(ClientMethod::SessionRequestPermission->value, new RequestPermissionRequest(
                sessionId: 'sess_1',
                toolCall: new ToolCallUpdate(toolCallId: 'call_1', title: 'deploy'),
                options: [new PermissionOption('allow', 'Allow', PermissionOptionKind::AllowOnce)],
            ), $cancellation->getCancellation());
            self::fail('The request should have been withdrawn.');
        } catch (CancelledException) {
        }

        self::assertTrue($withdrawn->getFuture()->await());
    }
}
