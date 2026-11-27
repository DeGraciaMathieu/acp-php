<?php

declare(strict_types=1);

namespace Acp\Tests\Rpc;

use Acp\Exception\ConnectionClosed;
use Acp\Exception\RpcError;
use Acp\Rpc\Connection;
use Acp\Rpc\InMemoryTransport;
use Acp\Schema\ErrorCode;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

final class ConnectionTest extends TestCase
{
    #[Test]
    public function a_request_gets_its_result(): void
    {
        [$client] = $this->pair(fn (string $method, array $params): array => ['echo' => $params['value']]);

        self::assertSame(['echo' => 42], $client->request('echo', ['value' => 42]));
    }

    #[Test]
    public function an_error_response_becomes_an_exception(): void
    {
        [$client] = $this->pair(fn () => throw new RpcError(-32000, 'Authentication required', ['hint' => 'login']));

        try {
            $client->request('session/new', []);
            self::fail('An RpcError was expected.');
        } catch (RpcError $e) {
            self::assertTrue($e->isAuthRequired());
            self::assertSame(ErrorCode::AuthenticationRequired, $e->errorCode());
            self::assertSame(['hint' => 'login'], $e->data());
        }
    }

    #[Test]
    public function unexpected_handler_failures_become_internal_errors(): void
    {
        [$client] = $this->pair(fn () => throw new \RuntimeException('boom'));

        try {
            $client->request('anything', []);
            self::fail('An RpcError was expected.');
        } catch (RpcError $e) {
            self::assertSame(ErrorCode::InternalError, $e->errorCode());
            self::assertSame('boom', $e->getMessage());
        }
    }

    #[Test]
    public function notifications_arrive_in_order(): void
    {
        $received = [];
        [$client] = $this->pair(
            fn () => null,
            function (string $method, array $params) use (&$received): void {
                $received[] = $params['n'];
            },
        );

        foreach (range(1, 20) as $n) {
            $client->notify('tick', ['n' => $n]);
        }
        $client->request('flush', []);

        self::assertSame(range(1, 20), $received);
    }

    #[Test]
    public function requests_are_handled_concurrently(): void
    {
        $release = new DeferredFuture();
        [$client] = $this->pair(function (string $method) use ($release): string {
            if ($method === 'slow') {
                $release->getFuture()->await();
            }

            return $method;
        });

        $slow = \Amp\async(fn () => $client->request('slow', []));
        self::assertSame('fast', $client->request('fast', []));

        $release->complete();
        self::assertSame('slow', $slow->await());
    }

    #[Test]
    public function cancelling_a_request_tells_the_peer(): void
    {
        $handlerCancelled = new DeferredFuture();
        [$client] = $this->pair(function (string $method, array $params, Cancellation $cancellation) use ($handlerCancelled): null {
            try {
                (new DeferredFuture())->getFuture()->await($cancellation);
            } catch (CancelledException) {
                $handlerCancelled->complete(true);
            }

            return null;
        });

        $cancellation = new DeferredCancellation();
        \Amp\async(function () use ($cancellation): void {
            delay(0.01);
            $cancellation->cancel();
        })->ignore();

        try {
            $client->request('long', [], $cancellation->getCancellation());
            self::fail('The request should have been cancelled.');
        } catch (CancelledException) {
        }

        self::assertTrue($handlerCancelled->getFuture()->await());
    }

    #[Test]
    public function pending_requests_fail_when_the_connection_closes(): void
    {
        [$client, $server] = $this->pair(function () use (&$server): null {
            $server->close();

            return null;
        });

        $this->expectException(ConnectionClosed::class);
        $client->request('anything', []);
    }

    #[Test]
    public function unparsable_messages_are_answered_with_a_parse_error(): void
    {
        [$a, $b] = InMemoryTransport::pair();
        $errors = [];
        $server = new Connection($b, fn () => null, fn () => null, function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });
        $server->start();

        $a->send('{not json');
        $response = json_decode((string) $a->receive(), true);

        self::assertSame(ErrorCode::ParseError->value, $response['error']['code']);
        self::assertNull($response['id']);
        self::assertCount(1, $errors);
    }

    #[Test]
    public function empty_params_are_sent_as_an_object(): void
    {
        [$a, $b] = InMemoryTransport::pair();
        $client = new Connection($a, fn () => null, fn () => null);
        $client->start();

        $client->notify('ping', []);

        self::assertSame('{"jsonrpc":"2.0","method":"ping","params":{}}', $b->receive());
    }

    /**
     * @param \Closure(string, array<string, mixed>, Cancellation): mixed $onRequest
     * @param (\Closure(string, array<string, mixed>): void)|null $onNotification
     *
     * @return array{0: Connection, 1: Connection}
     */
    private function pair(\Closure $onRequest, ?\Closure $onNotification = null): array
    {
        [$a, $b] = InMemoryTransport::pair();
        $client = new Connection($a, fn () => throw RpcError::methodNotFound('none'), fn () => null);
        $server = new Connection($b, $onRequest, $onNotification ?? fn () => null);
        $client->start();
        $server->start();

        return [$client, $server];
    }
}
