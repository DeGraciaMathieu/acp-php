<?php

declare(strict_types=1);

namespace Acp\Rpc;

/**
 * Prints each raw message on one line, for development:
 *
 *     → #3 session/prompt {"sessionId":"s1","prompt":[…]}
 *     ← session/update {"sessionId":"s1","update":{…}}
 *     ← #3 result {"stopReason":"end_turn"}
 *
 * Pass it as the onMessage observer of connect().
 */
final class Dumper
{
    /**
     * @param resource $stream
     */
    public function __construct(
        private readonly mixed $stream = STDERR,
    ) {
    }

    public function __invoke(Direction $direction, string $message): void
    {
        fwrite($this->stream, $this->format($direction, $message) . "\n");
    }

    public function format(Direction $direction, string $message): string
    {
        $decoded = json_decode($message, true);
        if (! is_array($decoded)) {
            return "{$direction->value} {$message}";
        }

        $id = isset($decoded['id']) && (is_int($decoded['id']) || is_string($decoded['id'])) ? "#{$decoded['id']} " : '';
        $label = match (true) {
            is_string($decoded['method'] ?? null) => $decoded['method'],
            array_key_exists('error', $decoded) => 'error',
            default => 'result',
        };
        $payload = $decoded['params'] ?? $decoded['error'] ?? $decoded['result'] ?? null;

        return "{$direction->value} {$id}{$label} " . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
