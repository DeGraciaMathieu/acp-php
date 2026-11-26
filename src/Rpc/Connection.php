<?php

declare(strict_types=1);

namespace Acp\Rpc;

use Acp\Exception\ConnectionClosed;
use Acp\Exception\InvalidPayload;
use Acp\Exception\RpcError;
use Acp\Schema\ErrorCode;
use Acp\Schema\ProtocolMethod;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Closure;
use JsonException;
use Throwable;

use function Amp\async;

/**
 * One side of a JSON-RPC 2.0 conversation, as ACP uses it: both peers send requests,
 * responses and notifications over the same transport.
 *
 * Notifications are handled in order, inside the read loop. Requests are handled
 * concurrently, each in its own fiber, so a handler can wait (for a human, for a file)
 * without blocking the stream of notifications.
 */
final class Connection
{
    private int $nextId = 0;

    /** @var array<int|string, array{DeferredFuture<mixed>, string}> pending outgoing requests */
    private array $pending = [];

    /** @var array<int|string, DeferredCancellation> incoming requests being handled */
    private array $inFlight = [];

    private bool $closed = false;

    /** @var DeferredFuture<null> */
    private DeferredFuture $done;

    /**
     * @param Closure(string, array<string, mixed>, Cancellation): mixed $onRequest Returns the result (JsonSerializable or array), throws RpcError to answer with an error.
     * @param Closure(string, array<string, mixed>): void $onNotification
     * @param Closure(Throwable): void|null $onError Problems that cannot be reported to the peer (unparsable messages, failing notification handlers).
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly Closure $onRequest,
        private readonly Closure $onNotification,
        private readonly ?Closure $onError = null,
    ) {
        $this->done = new DeferredFuture();
    }

    /**
     * Starts reading messages in the background.
     */
    public function start(): void
    {
        async($this->readLoop(...))->ignore();
    }

    /**
     * Sends a request and waits for its result.
     *
     * @param array<string, mixed>|object $params
     *
     * @throws RpcError when the peer answers with an error
     * @throws ConnectionClosed
     * @throws CancelledException when the cancellation fires; the peer is told with $/cancel_request
     */
    public function request(string $method, array|object $params, ?Cancellation $cancellation = null): mixed
    {
        if ($this->closed) {
            throw ConnectionClosed::whileSending();
        }

        $id = $this->nextId++;
        /** @var DeferredFuture<mixed> $deferred */
        $deferred = new DeferredFuture();
        $this->pending[$id] = [$deferred, $method];

        try {
            $this->write(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => self::object($params)]);

            return $deferred->getFuture()->await($cancellation);
        } catch (CancelledException $e) {
            if (isset($this->pending[$id]) && ! $this->closed) {
                $this->notify(ProtocolMethod::CancelRequest->value, ['requestId' => $id]);
            }
            throw $e;
        } finally {
            unset($this->pending[$id]);
        }
    }

    /**
     * @param array<string, mixed>|object $params
     */
    public function notify(string $method, array|object $params): void
    {
        $this->write(['jsonrpc' => '2.0', 'method' => $method, 'params' => self::object($params)]);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->transport->close();
        $this->shutdown();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Completes once the connection is closed, by either side.
     *
     * @return Future<null>
     */
    public function closed(): Future
    {
        return $this->done->getFuture();
    }

    private function readLoop(): void
    {
        try {
            while (($line = $this->transport->receive()) !== null) {
                $this->dispatch($line);
            }
        } catch (Throwable $e) {
            if (! $this->closed) {
                $this->report($e);
            }
        }

        $this->closed = true;
        $this->shutdown();
    }

    private function dispatch(string $line): void
    {
        try {
            $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->report($e);
            $this->respondError(null, new RpcError(ErrorCode::ParseError->value, 'Parse error'));

            return;
        }

        if (! is_array($message)) {
            $this->respondError(null, new RpcError(ErrorCode::InvalidRequest->value, 'Invalid request'));

            return;
        }

        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;

        if (is_string($method)) {
            $params = is_array($message['params'] ?? null) ? $message['params'] : [];
            if (array_key_exists('id', $message)) {
                $this->handleRequest($id, $method, $params);
            } else {
                $this->handleNotification($method, $params);
            }

            return;
        }

        if ((is_int($id) || is_string($id)) && isset($this->pending[$id])) {
            [$deferred, $pendingMethod] = $this->pending[$id];
            unset($this->pending[$id]);
            if (isset($message['error']) && is_array($message['error'])) {
                $deferred->error(new RpcError(
                    is_int($message['error']['code'] ?? null) ? $message['error']['code'] : ErrorCode::InternalError->value,
                    is_string($message['error']['message'] ?? null) ? $message['error']['message'] : "Error in {$pendingMethod}",
                    $message['error']['data'] ?? null,
                ));
            } else {
                $deferred->complete($message['result'] ?? null);
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleRequest(mixed $id, string $method, array $params): void
    {
        $key = is_int($id) || is_string($id) ? $id : null;
        $cancellation = new DeferredCancellation();
        if ($key !== null) {
            $this->inFlight[$key] = $cancellation;
        }

        async(function () use ($id, $key, $method, $params, $cancellation): void {
            try {
                $result = ($this->onRequest)($method, $params, $cancellation->getCancellation());
                $this->write(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result ?? new \stdClass()]);
            } catch (RpcError $e) {
                $this->respondError($id, $e);
            } catch (InvalidPayload $e) {
                $this->respondError($id, RpcError::invalidParams($e->getMessage()));
            } catch (CancelledException) {
                $this->respondError($id, RpcError::requestCancelled());
            } catch (ConnectionClosed) {
                // Nothing to answer to.
            } catch (Throwable $e) {
                $this->report($e);
                $this->respondError($id, RpcError::internal($e->getMessage()));
            } finally {
                if ($key !== null) {
                    unset($this->inFlight[$key]);
                }
            }
        })->ignore();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleNotification(string $method, array $params): void
    {
        if ($method === ProtocolMethod::CancelRequest->value) {
            $requestId = $params['requestId'] ?? null;
            if ((is_int($requestId) || is_string($requestId)) && isset($this->inFlight[$requestId])) {
                $this->inFlight[$requestId]->cancel();
            }

            return;
        }

        try {
            ($this->onNotification)($method, $params);
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    private function respondError(mixed $id, RpcError $error): void
    {
        try {
            $this->write(['jsonrpc' => '2.0', 'id' => $id, 'error' => $error->toArray()]);
        } catch (ConnectionClosed) {
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function write(array $message): void
    {
        if ($this->closed) {
            throw ConnectionClosed::whileSending();
        }

        $this->transport->send(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function shutdown(): void
    {
        foreach ($this->pending as $id => [$deferred, $method]) {
            unset($this->pending[$id]);
            $deferred->error(ConnectionClosed::whileWaiting($method));
        }
        foreach ($this->inFlight as $cancellation) {
            $cancellation->cancel();
        }
        $this->inFlight = [];
        if (! $this->done->isComplete()) {
            $this->done->complete();
        }
    }

    private function report(Throwable $e): void
    {
        if ($this->onError !== null) {
            ($this->onError)($e);
        }
    }

    /**
     * Empty params must be sent as {} rather than [].
     *
     * @param array<string, mixed>|object $params
     *
     * @return array<string, mixed>|object
     */
    private static function object(array|object $params): array|object
    {
        return $params === [] ? new \stdClass() : $params;
    }
}
