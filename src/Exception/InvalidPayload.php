<?php

declare(strict_types=1);

namespace Acp\Exception;

use InvalidArgumentException;

/**
 * A message does not match the ACP schema.
 *
 * Over JSON-RPC this maps to the "Invalid params" error (-32602).
 */
final class InvalidPayload extends InvalidArgumentException implements AcpException
{
    private function __construct(
        private readonly string $path,
        private readonly string $reason,
    ) {
        parent::__construct(sprintf('Invalid ACP payload at "%s": %s', $path, $reason));
    }

    public static function missingField(string $field): self
    {
        return new self($field, 'field is required');
    }

    public static function unexpectedType(string $field, string $expected, mixed $actual): self
    {
        return new self($field, sprintf('expected %s, got %s', $expected, get_debug_type($actual)));
    }

    public static function noMatchingVariant(string $field, string $union): self
    {
        return new self($field, sprintf('does not match any variant of %s', $union));
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Prefixes the path with the parent field.
     */
    public function within(string $parent): self
    {
        if ($parent === '') {
            return $this;
        }

        $path = $this->path === '' ? $parent : $parent . '.' . $this->path;

        return new self($path, $this->reason);
    }
}
