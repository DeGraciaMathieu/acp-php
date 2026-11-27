<?php

declare(strict_types=1);

namespace Acp\Tests\Feature;

use Acp\Client\Permission\CallbackPermissions;
use Acp\Client\Permission\Outcome;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;
use Acp\Schema\StopReason;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;
use Amp\Cancellation;
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
}
