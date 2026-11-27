<?php

declare(strict_types=1);

namespace Acp\Tests\Feature;

use Acp\Client\ClientConnection;
use Acp\Exception\RpcError;
use Acp\Rpc\Connection;
use Acp\Rpc\InMemoryTransport;
use Acp\Rpc\Transport;
use Acp\Schema\ErrorCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An agent speaking raw JSON-RPC, to send what FakeAgent never would.
 */
final class MisbehavingAgentTest extends TestCase
{
    /** @var list<\Throwable> */
    private array $clientErrors = [];

    #[Test]
    public function a_malformed_request_is_answered_with_invalid_params(): void
    {
        $agent = $this->connectRawAgent();

        $error = $this->requestError($agent, 'session/request_permission', ['sessionId' => 'sess_1']);

        self::assertSame(ErrorCode::InvalidParams, $error->errorCode());
    }

    #[Test]
    public function unknown_messages_do_not_break_the_client(): void
    {
        $agent = $this->connectRawAgent();

        $agent->notify('_vendor/telemetry', ['event' => 'started']);
        $error = $this->requestError($agent, '_vendor/ping', []);

        self::assertSame(ErrorCode::MethodNotFound, $error->errorCode());
        self::assertSame([], $this->clientErrors);
    }

    #[Test]
    public function an_unparsable_message_is_answered_with_a_parse_error(): void
    {
        [$clientSide, $agentSide] = InMemoryTransport::pair();
        $this->connectClient($clientSide);

        $agentSide->send('{not json');
        $response = json_decode((string) $agentSide->receive(), true);

        self::assertSame(ErrorCode::ParseError->value, $response['error']['code']);
        self::assertCount(1, $this->clientErrors);
    }

    private function connectRawAgent(): Connection
    {
        [$clientSide, $agentSide] = InMemoryTransport::pair();
        $this->connectClient($clientSide);
        $agent = new Connection($agentSide, fn () => null, fn () => null);
        $agent->start();

        return $agent;
    }

    private function connectClient(Transport $transport): void
    {
        new ClientConnection($transport, onError: function (\Throwable $e): void {
            $this->clientErrors[] = $e;
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    private function requestError(Connection $agent, string $method, array $params): RpcError
    {
        try {
            $agent->request($method, $params);
        } catch (RpcError $e) {
            return $e;
        }

        self::fail('The client should have answered with an error.');
    }
}
