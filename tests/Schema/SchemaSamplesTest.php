<?php

declare(strict_types=1);

namespace Acp\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Builds a sample payload for every definition of the bundled JSON schema, filling every
 * property, then checks that the generated class hydrates it and serializes it back.
 */
final class SchemaSamplesTest extends TestCase
{
    private const MAX_DEPTH = 6;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $defs = null;

    /**
     * @return iterable<string, array{0: class-string, 1: array<string, mixed>}>
     */
    public static function definitions(): iterable
    {
        foreach (self::defs() as $name => $def) {
            $class = 'Acp\\Schema\\' . $name;
            if (! class_exists($class) || ! method_exists($class, 'fromArray')) {
                continue; // enums, aliases and JSON-RPC envelopes
            }

            $variants = $def['oneOf'] ?? $def['anyOf'] ?? [null];
            foreach (array_keys($variants) as $index) {
                if (is_array($variants[$index]) && ($variants[$index]['title'] ?? null) === 'other') {
                    continue;
                }
                $sample = self::sampleDefinition($name, 0, $index);
                if (is_array($sample)) {
                    yield $name . ($variants[$index] !== null ? " #{$index}" : '') => [$class, $sample];
                }
            }
        }
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $sample
     */
    #[Test]
    #[DataProvider('definitions')]
    public function every_definition_round_trips(string $class, array $sample): void
    {
        $object = $class::fromArray($sample);
        self::assertFalse(str_ends_with($object::class, '\\Unrecognized'), 'A known variant was not recognized: ' . json_encode($sample));
        $json = (string) json_encode($object, JSON_THROW_ON_ERROR);
        $again = $class::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));

        self::assertEquals($object, $again, $json);
        self::assertSame($json, json_encode($again, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array<string, mixed>> */
    private static function defs(): array
    {
        return self::$defs ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/resources/schema/v1/schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['$defs'];
    }

    private static function sampleDefinition(string $name, int $depth, ?int $index = null): mixed
    {
        $def = self::defs()[$name];
        $variants = $def['oneOf'] ?? $def['anyOf'] ?? null;

        if ($variants === null) {
            return self::sample($def, $depth);
        }

        // The requested variant (else the first one that is not a catch-all), merged with the definition's own properties.
        $variant = $index !== null ? $variants[$index] : $variants[0];
        if ($index === null) {
            foreach ($variants as $candidate) {
                if (($candidate['title'] ?? null) !== 'other') {
                    $variant = $candidate;
                    break;
                }
            }
        }

        $own = isset($def['properties']) ? self::sample(['type' => 'object'] + $def, $depth) : [];
        $picked = self::sample($variant, $depth);

        return is_array($own) && is_array($picked) && ! array_is_list($picked) ? $own + $picked : $picked;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function sample(array $schema, int $depth): mixed
    {
        if (isset($schema['$ref'])) {
            return self::sampleDefinition(substr($schema['$ref'], strlen('#/$defs/')), $depth + 1);
        }
        if (array_key_exists('const', $schema)) {
            return $schema['const'];
        }
        if (isset($schema['allOf']) && count($schema['allOf']) === 1 && ! isset($schema['properties'])) {
            return self::sample($schema['allOf'][0], $depth);
        }

        $options = $schema['anyOf'] ?? $schema['oneOf'] ?? null;
        if ($options !== null && ! isset($schema['properties'])) {
            foreach ($options as $option) {
                if (($option['type'] ?? null) !== 'null') {
                    return self::sample($option, $depth);
                }
            }
        }

        $types = array_values(array_diff((array) ($schema['type'] ?? []), ['null']));
        $type = $types[0] ?? (isset($schema['properties']) || isset($schema['allOf']) ? 'object' : null);

        return match ($type) {
            'string' => 'sample',
            'integer' => 7,
            'number' => 1.5,
            'boolean' => true,
            'array' => isset($schema['items']) && $depth < self::MAX_DEPTH ? [self::sample($schema['items'], $depth + 1)] : [],
            'object' => self::sampleObject($schema, $depth),
            default => 'anything',
        };

    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function sampleObject(array $schema, int $depth): array
    {
        $object = [];
        foreach ($schema['allOf'] ?? [] as $part) {
            $merged = self::sample($part, $depth);
            if (is_array($merged)) {
                $object += $merged;
            }
        }

        $required = $schema['required'] ?? [];
        foreach ($schema['properties'] ?? [] as $property => $propertySchema) {
            if ($depth >= self::MAX_DEPTH && ! in_array($property, $required, true)) {
                continue;
            }
            $object[$property] = self::sample($propertySchema, $depth + 1);
        }

        if (($schema['additionalProperties'] ?? null) !== null && is_array($schema['additionalProperties']) && $depth < self::MAX_DEPTH) {
            $object['key'] = self::sample($schema['additionalProperties'], $depth + 1);
        }

        return $object;
    }
}
