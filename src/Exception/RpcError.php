<?php

declare(strict_types=1);

namespace Acp\Exception;

use Acp\Schema\ErrorCode;
use RuntimeException;

/**
 * A JSON-RPC error: either received from the peer, or thrown by a handler to answer with one.
 */
final class RpcError extends RuntimeException implements AcpException
{
    public function __construct(
        int $code,
        string $message,
        private readonly mixed $data = null,
    ) {
        parent::__construct($message, $code);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(ErrorCode::MethodNotFound->value, sprintf('Method not found: %s', $method));
    }

    public static function invalidParams(string $message): self
    {
        return new self(ErrorCode::InvalidParams->value, $message);
    }

    public static function internal(string $message): self
    {
        return new self(ErrorCode::InternalError->value, $message);
    }

    public static function requestCancelled(): self
    {
        return new self(ErrorCode::RequestCancelled->value, 'Request cancelled');
    }

    /**
     * Error code as an ACP enum case when known, raw int otherwise.
     */
    public function errorCode(): ErrorCode|int
    {
        return ErrorCode::tryFrom($this->getCode()) ?? $this->getCode();
    }

    public function data(): mixed
    {
        return $this->data;
    }

    public function isAuthRequired(): bool
    {
        return $this->getCode() === ErrorCode::AuthenticationRequired->value;
    }

    /**
     * @return array{code: int, message: string, data?: mixed}
     */
    public function toArray(): array
    {
        $error = ['code' => $this->getCode(), 'message' => $this->getMessage()];
        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return $error;
    }
}
