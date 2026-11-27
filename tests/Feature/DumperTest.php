<?php

declare(strict_types=1);

namespace Acp\Tests\Feature;

use Acp\Client\Permission\ApproveAll;
use Acp\Rpc\Dumper;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DumperTest extends TestCase
{
    #[Test]
    public function it_shows_every_exchange_of_a_turn_including_permissions(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn): null {
                $turn->requestPermission('call_1', 'npm test');
                $turn->say('Done.');

                return null;
            })
            ->connect(permissions: new ApproveAll(), onMessage: new Dumper($stream));
        $client->initialize();
        $client->newSession(cwd: '/tmp')->prompt('test')->wait();

        rewind($stream);
        $lines = explode("\n", trim((string) stream_get_contents($stream)));

        self::assertStringStartsWith('→ #0 initialize {', $lines[0]);
        self::assertStringStartsWith('← #0 result {', $lines[1]);
        self::assertContains('→ #0 result {"outcome":{"outcome":"selected","optionId":"allow"}}', $lines);
        self::assertNotEmpty(preg_grep('/^← #\d+ session\/request_permission \{/', $lines));
        self::assertNotEmpty(preg_grep('/^← session\/update \{/', $lines));
        self::assertSame('← #2 result {"stopReason":"end_turn"}', end($lines));
    }
}
