<?php

declare(strict_types=1);

namespace Acp\Tests\Rpc;

use Acp\Rpc\Direction;
use Acp\Rpc\Dumper;
use Acp\Testing\FakeAgent;
use PHPUnit\Framework\TestCase;

final class DumperTest extends TestCase
{
    public function test_a_request_shows_its_id_method_and_params(): void
    {
        $line = (new Dumper())->format(Direction::Sent, '{"jsonrpc":"2.0","id":3,"method":"session/prompt","params":{"sessionId":"s1"}}');

        self::assertSame('→ #3 session/prompt {"sessionId":"s1"}', $line);
    }

    public function test_a_notification_has_no_id(): void
    {
        $line = (new Dumper())->format(Direction::Received, '{"jsonrpc":"2.0","method":"session/update","params":{"sessionId":"s1"}}');

        self::assertSame('← session/update {"sessionId":"s1"}', $line);
    }

    public function test_responses_show_their_result_or_error(): void
    {
        $dumper = new Dumper();

        self::assertSame('← #3 result {"stopReason":"end_turn"}', $dumper->format(Direction::Received, '{"jsonrpc":"2.0","id":3,"result":{"stopReason":"end_turn"}}'));
        self::assertSame('← #4 error {"code":-32000,"message":"Authentication required"}', $dumper->format(Direction::Received, '{"jsonrpc":"2.0","id":4,"error":{"code":-32000,"message":"Authentication required"}}'));
    }

    public function test_invalid_json_is_printed_as_is(): void
    {
        self::assertSame('← not json', (new Dumper())->format(Direction::Received, 'not json'));
    }

    public function test_it_writes_one_line_per_message_exchanged_by_the_client(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $client = FakeAgent::create()->connect(onMessage: new Dumper($stream));
        $client->initialize();

        rewind($stream);
        $lines = explode("\n", trim((string) stream_get_contents($stream)));

        self::assertCount(2, $lines);
        self::assertStringStartsWith('→ #', $lines[0]);
        self::assertStringContainsString(' initialize {', $lines[0]);
        self::assertStringStartsWith('← #', $lines[1]);
        self::assertStringContainsString(' result {', $lines[1]);
    }
}
