<?php

declare(strict_types=1);

namespace Acp\Tests\Feature;

use Acp\Schema\SessionUpdate;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An agent speaking a newer version of the protocol than this SDK knows.
 */
final class NewerAgentTest extends TestCase
{
    #[Test]
    public function an_unknown_update_reaches_the_host_untouched(): void
    {
        $payload = ['sessionUpdate' => 'telemetry_v3', 'cpu' => 0.4];
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn) use ($payload): null {
                $turn->send(SessionUpdate::fromArray($payload));
                $turn->say('Done.');

                return null;
            })
            ->connect();
        $client->initialize();

        $turn = $client->newSession(cwd: '/tmp')->prompt('hi');
        $updates = iterator_to_array($turn->updates(), false);

        self::assertInstanceOf(SessionUpdate\Unrecognized::class, $updates[0]);
        self::assertSame('telemetry_v3', $updates[0]->tag());
        self::assertSame($payload, $updates[0]->data);
        self::assertSame('Done.', $turn->text());
    }

    #[Test]
    public function an_unknown_stop_reason_is_kept_raw(): void
    {
        $client = FakeAgent::create()
            ->onPrompt(fn (): string => 'budget_exceeded')
            ->connect();
        $client->initialize();

        $turn = $client->newSession(cwd: '/tmp')->prompt('hi');

        self::assertSame('budget_exceeded', $turn->stopReason());
    }
}
