<?php

declare(strict_types=1);

namespace Acp\Support;

use Acp\Exception\InvalidPayload;
use BackedEnum;
use Closure;
use stdClass;

/**
 * Hydration helpers used by the generated schema classes.
 *
 * @internal
 */
final class Cast
{
    /**
     * @param array<string, mixed> $data
     */
    public static function required(array $data, string $field): mixed
    {
        if (! array_key_exists($field, $data)) {
            throw InvalidPayload::missingField($field);
        }

        return $data[$field];
    }

    public static function str(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw InvalidPayload::unexpectedType($field, 'string', $value);
        }

        return $value;
    }

    public static function int(mixed $value, string $field): int
    {
        if (! is_int($value)) {
            throw InvalidPayload::unexpectedType($field, 'int', $value);
        }

        return $value;
    }

    public static function float(mixed $value, string $field): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw InvalidPayload::unexpectedType($field, 'number', $value);
        }

        return (float) $value;
    }

    public static function bool(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw InvalidPayload::unexpectedType($field, 'bool', $value);
        }

        return $value;
    }

    /**
     * A JSON object, decoded as an associative array.
     *
     * @return array<string, mixed>
     */
    public static function obj(mixed $value, string $field): array
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw InvalidPayload::unexpectedType($field, 'object', $value);
        }

        return $value;
    }

    /**
     * @param list<string> $types Accepted types, as returned by get_debug_type() ("string", "int", "float", "bool", "array", "null").
     */
    public static function union(mixed $value, string $field, array $types): mixed
    {
        $type = get_debug_type($value);

        if ($type === 'int' && in_array('float', $types, true) && ! in_array('int', $types, true)) {
            return (float) $value;
        }

        if (! in_array($type, $types, true)) {
            throw InvalidPayload::unexpectedType($field, implode('|', $types), $value);
        }

        return $value;
    }

    /**
     * Open string enums: a known value becomes an enum case, an unknown one is kept
     * as a raw string so that a newer agent never breaks an older client.
     *
     * @template T of BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|string
     */
    public static function stringEnum(string $enum, mixed $value, string $field): BackedEnum|string
    {
        if (! is_string($value)) {
            throw InvalidPayload::unexpectedType($field, 'string', $value);
        }

        return $enum::tryFrom($value) ?? $value;
    }

    /**
     * Open integer enums, see stringEnum().
     *
     * @template T of BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|int
     */
    public static function intEnum(string $enum, mixed $value, string $field): BackedEnum|int
    {
        if (! is_int($value)) {
            throw InvalidPayload::unexpectedType($field, 'int', $value);
        }

        return $enum::tryFrom($value) ?? $value;
    }

    /**
     * @template T
     *
     * @param Closure(mixed): T $item
     *
     * @return list<T>
     */
    public static function listOf(mixed $value, string $field, Closure $item, bool $skipInvalid = false): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidPayload::unexpectedType($field, 'list', $value);
        }

        $items = [];

        foreach ($value as $index => $raw) {
            try {
                $items[] = $item($raw);
            } catch (InvalidPayload $e) {
                if (! $skipInvalid) {
                    throw $e->within($field . '[' . $index . ']');
                }
            }
        }

        return $items;
    }

    /**
     * @template T
     *
     * @param Closure(mixed): T $item
     *
     * @return array<string, T>
     */
    public static function mapOf(mixed $value, string $field, Closure $item): array
    {
        $items = [];

        foreach (self::obj($value, $field) as $key => $raw) {
            try {
                $items[(string) $key] = $item($raw);
            } catch (InvalidPayload $e) {
                throw $e->within($field . '.' . $key);
            }
        }

        return $items;
    }

    /**
     * Optional fields: a missing or null value yields the default.
     *
     * @template T
     * @template D
     *
     * @param Closure(mixed): T $hydrate
     * @param D $default
     *
     * @return T|D
     */
    public static function optional(mixed $value, Closure $hydrate, mixed $default = null): mixed
    {
        return $value === null ? $default : $hydrate($value);
    }

    /**
     * Fields marked "x-deserialize-default-on-error" in the schema: an invalid value
     * falls back to the default instead of rejecting the whole message.
     *
     * @template T
     * @template D
     *
     * @param Closure(): T $hydrate
     * @param D $default
     *
     * @return T|D
     */
    public static function lenient(Closure $hydrate, mixed $default): mixed
    {
        try {
            return $hydrate();
        } catch (InvalidPayload) {
            return $default;
        }
    }

    /**
     * Hydrates a nested object, anchoring its errors under the parent field.
     *
     * @template T
     *
     * @param Closure(mixed): T $hydrate
     *
     * @return T
     */
    public static function at(string $field, mixed $value, Closure $hydrate): mixed
    {
        try {
            return $hydrate($value);
        } catch (InvalidPayload $e) {
            throw $e->within($field);
        }
    }

    /**
     * Keeps JSON objects as objects when they are empty ({} rather than []).
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>|stdClass
     */
    public static function jsonObject(array $value): array|stdClass
    {
        return $value === [] ? new stdClass() : $value;
    }
}
