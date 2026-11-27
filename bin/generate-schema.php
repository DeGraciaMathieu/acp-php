#!/usr/bin/env php
<?php

/**
 * Generates src/Schema from the official ACP JSON schema.
 *
 *   php bin/generate-schema.php           (re)generate src/Schema
 *   php bin/generate-schema.php --check   exit 1 if src/Schema is out of date
 *
 * Mapping rules:
 * - object definitions            -> final readonly classes with fromArray() / jsonSerialize()
 * - string or integer constants   -> backed enums, kept "open" (unknown values stay raw)
 * - tagged unions                 -> abstract readonly class + one final class per variant,
 *                                    plus an Unrecognized variant for forward compatibility
 * - unions sharing own properties -> a single class merging every variant's fields
 * - scalar unions / aliases       -> native PHP types
 * - JSON-RPC envelopes            -> skipped (handled by the Rpc layer)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$check = in_array('--check', $_SERVER['argv'] ?? [], true);
$schemaDir = $root . '/resources/schema/v1';

$schema = json_decode((string) file_get_contents($schemaDir . '/schema.json'), true, flags: JSON_THROW_ON_ERROR);
$meta = json_decode((string) file_get_contents($schemaDir . '/meta.json'), true, flags: JSON_THROW_ON_ERROR);
$release = trim((string) file_get_contents($schemaDir . '/VERSION'));

$files = (new SchemaGenerator($schema['$defs'], $meta, $release))->generate();
$target = $root . '/src/Schema';

if ($check) {
    $existing = [];
    if (is_dir($target)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $existing[substr($file->getPathname(), strlen($target) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($existing);
    ksort($files);
    if ($existing !== $files) {
        $stale = array_merge(array_keys(array_diff_key($files, $existing)), array_keys(array_diff_key($existing, $files)));
        foreach ($files as $path => $content) {
            if (isset($existing[$path]) && $existing[$path] !== $content) {
                $stale[] = $path;
            }
        }
        fwrite(STDERR, "src/Schema is out of date, run `composer generate`.\n  " . implode("\n  ", array_slice($stale, 0, 20)) . "\n");
        exit(1);
    }
    echo "src/Schema is up to date (" . count($files) . " files).\n";
    exit(0);
}

removeDirectory($target);
foreach ($files as $path => $content) {
    $file = $target . '/' . $path;
    if (! is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }
    file_put_contents($file, $content);
}
echo 'Generated ' . count($files) . " files in src/Schema from {$release}.\n";

function removeDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

final class SchemaGenerator
{
    private const NS = 'Acp\\Schema';

    /** Names PHP refuses (or discourages) as class names. */
    private const RESERVED = [
        'array', 'bool', 'callable', 'class', 'default', 'enum', 'false', 'float', 'fn', 'function', 'int',
        'iterable', 'list', 'match', 'mixed', 'never', 'null', 'numeric', 'object', 'parent', 'resource',
        'self', 'static', 'string', 'true', 'void',
    ];

    /** @var array<string, string> definition name => kind */
    private array $kinds = [];

    /**
     * @param array<string, array<string, mixed>> $defs
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly array $defs,
        private readonly array $meta,
        private readonly string $release,
    ) {
    }

    /**
     * @return array<string, string> relative path => file content
     */
    public function generate(): array
    {
        foreach ($this->defs as $name => $def) {
            $this->kinds[$name] = $this->kindOf($name, $def);
        }

        $files = [];
        foreach ($this->defs as $name => $def) {
            switch ($this->kinds[$name]) {
                case 'enum':
                    $files[$name . '.php'] = $this->renderEnum($name, $def);
                    break;
                case 'struct':
                    $files[$name . '.php'] = $this->renderStruct($name, $this->structFields($def), $def);
                    break;
                case 'merged':
                    $files[$name . '.php'] = $this->renderStruct($name, $this->mergedFields($def), $def);
                    break;
                case 'union':
                    foreach ($this->renderUnion($name, $def) as $path => $content) {
                        $files[$path] = $content;
                    }
                    break;
            }
        }

        foreach (['agentMethods' => 'AgentMethod', 'clientMethods' => 'ClientMethod', 'protocolMethods' => 'ProtocolMethod'] as $key => $enum) {
            $files[$enum . '.php'] = $this->renderMethods($enum, $this->meta[$key]);
        }
        $files['SchemaVersion.php'] = $this->renderVersion();

        ksort($files);

        return $files;
    }

    // ------------------------------------------------------------------
    // Classification
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $def */
    private function kindOf(string $name, array $def): string
    {
        $properties = $def['properties'] ?? [];
        if (isset($properties['params']) || isset($properties['jsonrpc']) || in_array($name, ['AgentResponse', 'ClientResponse'], true)) {
            return 'skip';
        }

        $variants = $def['oneOf'] ?? $def['anyOf'] ?? null;
        if ($variants !== null) {
            if ($this->isEnum($variants)) {
                return 'enum';
            }
            if ($this->isScalarUnion($variants)) {
                return 'alias';
            }

            return isset($def['properties']) ? 'merged' : 'union';
        }

        if (($def['type'] ?? null) === 'object' || isset($def['properties'])) {
            return 'struct';
        }

        return 'alias';
    }

    /** @param list<array<string, mixed>> $variants */
    private function isEnum(array $variants): bool
    {
        $constants = 0;
        foreach ($variants as $variant) {
            $type = $variant['type'] ?? null;
            if (! in_array($type, ['string', 'integer'], true) || isset($variant['properties'])) {
                return false;
            }
            if (array_key_exists('const', $variant)) {
                $constants++;
            } elseif (strtolower((string) ($variant['title'] ?? '')) !== 'other') {
                return false; // only a catch-all "other" variant may lack a constant
            }
        }

        return $constants > 0;
    }

    /** @param list<array<string, mixed>> $variants */
    private function isScalarUnion(array $variants): bool
    {
        foreach ($variants as $variant) {
            if (isset($variant['allOf']) || isset($variant['$ref']) || isset($variant['properties']) || array_key_exists('const', $variant)) {
                return false;
            }
            if (! in_array($variant['type'] ?? null, ['string', 'integer', 'number', 'boolean', 'null', 'array'], true)) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Types
    // ------------------------------------------------------------------

    /**
     * Resolves a property schema to a type descriptor and whether it accepts null.
     *
     * @param array<string, mixed> $schema
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function typeOf(array $schema): array
    {
        if (isset($schema['$ref'])) {
            return [$this->refType($this->refName($schema['$ref'])), false];
        }
        if (isset($schema['allOf']) && count($schema['allOf']) === 1 && isset($schema['allOf'][0]['$ref'])) {
            return [$this->refType($this->refName($schema['allOf'][0]['$ref'])), false];
        }
        if (isset($schema['anyOf']) || isset($schema['oneOf'])) {
            $options = $schema['anyOf'] ?? $schema['oneOf'];
            $nullable = false;
            $rest = [];
            foreach ($options as $option) {
                if (($option['type'] ?? null) === 'null') {
                    $nullable = true;
                } else {
                    $rest[] = $option;
                }
            }
            if (count($rest) === 1) {
                [$type, $innerNullable] = $this->typeOf($rest[0]);

                return [$type, $nullable || $innerNullable];
            }

            return [['t' => 'mixed'], false];
        }

        $types = (array) ($schema['type'] ?? []);
        $nullable = in_array('null', $types, true);
        $types = array_values(array_filter($types, static fn (string $t): bool => $t !== 'null'));

        if ($types === []) {
            return [['t' => 'mixed'], false];
        }
        if (count($types) > 1) {
            return [['t' => 'union', 'types' => array_map($this->debugType(...), $types)], $nullable];
        }

        return [match ($types[0]) {
            'string' => ['t' => 'string'],
            'integer' => ['t' => 'int'],
            'number' => ['t' => 'float'],
            'boolean' => ['t' => 'bool'],
            'array' => ['t' => 'list', 'item' => isset($schema['items']) ? $this->typeOf($schema['items'])[0] : ['t' => 'mixed']],
            'object' => is_array($schema['additionalProperties'] ?? null)
                ? ['t' => 'map', 'item' => $this->typeOf($schema['additionalProperties'])[0]]
                : ['t' => 'object'],
            default => ['t' => 'mixed'],
        }, $nullable];
    }

    /** @return array<string, mixed> */
    private function refType(string $name): array
    {
        $def = $this->defs[$name] ?? throw new RuntimeException("Unknown definition {$name}");

        return match ($this->kinds[$name]) {
            'enum' => ['t' => 'enum', 'name' => $name, 'backing' => $this->enumBacking($def)],
            'struct', 'merged', 'union' => ['t' => 'class', 'name' => $name],
            'alias' => $this->aliasType($def),
            default => ['t' => 'mixed'],
        };
    }

    /**
     * @param array<string, mixed> $def
     *
     * @return array<string, mixed>
     */
    private function aliasType(array $def): array
    {
        $variants = $def['oneOf'] ?? $def['anyOf'] ?? null;
        if ($variants !== null) {
            $types = array_values(array_unique(array_map(fn (array $v): string => $this->debugType($v['type']), $variants)));

            return ['t' => 'union', 'types' => $types];
        }

        return $this->typeOf($def)[0];
    }

    private function debugType(string $jsonType): string
    {
        return match ($jsonType) {
            'string' => 'string',
            'integer' => 'int',
            'number' => 'float',
            'boolean' => 'bool',
            'null' => 'null',
            default => 'array',
        };
    }

    private function refName(string $ref): string
    {
        return substr($ref, strlen('#/$defs/'));
    }

    /** @param array<string, mixed> $def */
    private function enumBacking(array $def): string
    {
        foreach ($def['oneOf'] ?? $def['anyOf'] as $variant) {
            if (array_key_exists('const', $variant)) {
                return is_int($variant['const']) ? 'int' : 'string';
            }
        }

        return 'string';
    }

    /** @param array<string, mixed> $type */
    private function phpType(array $type, bool $nullable): string
    {
        $php = match ($type['t']) {
            'string', 'int', 'float', 'bool' => $type['t'],
            'list', 'map', 'object' => 'array',
            'enum' => $type['name'] . '|' . $type['backing'],
            'class' => $type['name'],
            'union' => implode('|', $type['types']),
            default => 'mixed',
        };

        if (! $nullable || $php === 'mixed' || str_contains($php, 'null')) {
            return $php;
        }

        return str_contains($php, '|') ? $php . '|null' : '?' . $php;
    }

    /** @param array<string, mixed> $type */
    private function docType(array $type, bool $nullable): string
    {
        $doc = match ($type['t']) {
            'list' => 'list<' . $this->docType($type['item'], false) . '>',
            'map' => 'array<string, ' . $this->docType($type['item'], false) . '>',
            'object' => 'array<string, mixed>',
            'union' => str_replace('array', 'array<mixed>', $this->phpType($type, false)),
            default => $this->phpType($type, false),
        };

        if ($nullable && $doc !== 'mixed' && ! str_contains($doc, 'null')) {
            $doc .= '|null';
        }

        return $doc;
    }

    /**
     * PHP expression hydrating $var into the given type.
     *
     * @param array<string, mixed> $type
     */
    private function hydrate(array $type, string $var, string $field, bool $skipInvalid = false): string
    {
        $f = var_export($field, true);

        return match ($type['t']) {
            'string' => "Cast::str({$var}, {$f})",
            'int' => "Cast::int({$var}, {$f})",
            'float' => "Cast::float({$var}, {$f})",
            'bool' => "Cast::bool({$var}, {$f})",
            'object' => "Cast::obj({$var}, {$f})",
            'enum' => 'Cast::' . $type['backing'] . "Enum({$type['name']}::class, {$var}, {$f})",
            'union' => "Cast::union({$var}, {$f}, " . $this->exportList($type['types']) . ')',
            'class' => "Cast::at({$f}, {$var}, static fn (mixed \$object): {$type['name']} => {$type['name']}::fromArray(Cast::obj(\$object, '')))",
            'list' => "Cast::listOf({$var}, {$f}, static fn (mixed \$item) => " . $this->hydrateItem($type['item']) . ($skipInvalid ? ', skipInvalid: true' : '') . ')',
            'map' => "Cast::mapOf({$var}, {$f}, static fn (mixed \$item) => " . $this->hydrateItem($type['item']) . ')',
            default => $var,
        };
    }

    /**
     * Hydration of a list or map item: errors are re-anchored by listOf()/mapOf().
     *
     * @param array<string, mixed> $type
     */
    private function hydrateItem(array $type): string
    {
        if ($type['t'] === 'class') {
            return "{$type['name']}::fromArray(Cast::obj(\$item, ''))";
        }

        return $this->hydrate($type, '$item', '');
    }

    /** @param list<string> $values */
    private function exportList(array $values): string
    {
        return '[' . implode(', ', array_map(static fn (string $v): string => var_export($v, true), $values)) . ']';
    }

    // ------------------------------------------------------------------
    // Fields
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $def
     * @param list<string> $skip
     *
     * @return array<string, array<string, mixed>> keyed by JSON name
     */
    private function structFields(array $def, bool $forceOptional = false, array $skip = []): array
    {
        $required = $forceOptional ? [] : ($def['required'] ?? []);
        $fields = [];
        foreach ($def['properties'] ?? [] as $json => $property) {
            if (in_array($json, $skip, true)) {
                continue;
            }
            $fields[$json] = $this->field($json, $property, in_array($json, $required, true));
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $property
     *
     * @return array<string, mixed>
     */
    private function field(string $json, array $property, bool $required): array
    {
        [$type, $nullable] = $this->typeOf($property);
        $php = $json === '_meta' ? 'meta' : lcfirst($this->pascal($json));

        $field = [
            'schema' => $property,
            'json' => $json,
            'php' => $php,
            'type' => $type,
            'required' => $required,
            'nullable' => $nullable,
            'lenient' => ! $required && ($property['x-deserialize-default-on-error'] ?? false),
            'skipInvalid' => $property['x-deserialize-skip-invalid-items'] ?? false,
            'description' => $property['description'] ?? '',
            'default' => null,       // PHP default expression, null = no default
            'hydrateDefault' => 'null',
            'omitIf' => null,        // serialization guard
        ];

        if ($required) {
            return $field;
        }

        $default = $property['default'] ?? null;
        $defaultOnNull = $property['x-deserialize-default-on-null'] ?? false;

        if ($type['t'] === 'bool' && is_bool($default)) {
            $field['nullable'] = false;
            $field['default'] = var_export($default, true);
            $field['hydrateDefault'] = $field['default'];
            $field['omitIf'] = $field['default'];
        } elseif (in_array($type['t'], ['list', 'map', 'object'], true) && ($defaultOnNull || $default === [])) {
            $field['nullable'] = false;
            $field['default'] = '[]';
            $field['hydrateDefault'] = '[]';
            $field['omitIf'] = '[]';
        } elseif ($type['t'] === 'enum' && is_string($default)) {
            $case = $this->pascal($default);
            $field['nullable'] = false;
            $field['default'] = "{$type['name']}::{$case}";
            $field['hydrateDefault'] = $field['default'];
        } elseif ($type['t'] === 'class' && is_array($default)) {
            // Object defaults: the field stays nullable for constructors, hydration fills the default.
            $field['nullable'] = true;
            $field['default'] = 'null';
            $field['hydrateDefault'] = "{$type['name']}::fromArray(" . $this->exportArray($default) . ')';
            $field['omitIf'] = 'null';
            $field['omitDefault'] = $field['hydrateDefault']; // defaults are not re-emitted
        } else {
            $field['nullable'] = true;
            $field['default'] = 'null';
            $field['omitIf'] = 'null';
        }

        return $field;
    }

    /**
     * Same field, recomputed as optional (nullable, with its default).
     *
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    private function optionalField(array $field): array
    {
        $optional = $this->field($field['json'], $field['schema'], false);
        $optional['type'] = $field['type'];

        return $optional;
    }

    /**
     * Unions that share properties of their own become one class with every variant's fields.
     *
     * @param array<string, mixed> $def
     *
     * @return array<string, array<string, mixed>>
     */
    private function mergedFields(array $def): array
    {
        $fields = $this->structFields($def);

        // A shared property every variant requires (usually the tag) is required.
        $variants = $def['oneOf'] ?? $def['anyOf'];
        $requiredEverywhere = array_reduce(
            $variants,
            static fn (?array $carry, array $v): array => $carry === null ? ($v['required'] ?? []) : array_intersect($carry, $v['required'] ?? []),
        ) ?? [];
        foreach ($variants as $variant) {
            $extra = $this->structFields($variant, forceOptional: true);
            foreach ($variant['allOf'] ?? [] as $part) {
                $extra += $this->flatten($this->refName($part['$ref']), forceOptional: true);
            }
            foreach ($extra as $json => $field) {
                $field = $field['required'] ? $this->optionalField($field) : $field;
                if (! isset($fields[$json])) {
                    $fields[$json] = $field;
                } elseif ($fields[$json]['type'] != $field['type']) {
                    $fields[$json]['type'] = ['t' => 'mixed'];
                }
            }
        }

        foreach ($fields as $json => $field) {
            if (! $field['required'] && in_array($json, $requiredEverywhere, true)) {
                $required = $this->field($json, $field['schema'], true);
                $required['type'] = $field['type'];
                $fields[$json] = $required;
            }
        }

        return $fields;
    }

    /**
     * @param list<string> $skip
     *
     * @return array<string, array<string, mixed>>
     */
    private function flatten(string $name, bool $forceOptional = false, array $skip = []): array
    {
        $def = $this->defs[$name];

        return match ($this->kinds[$name]) {
            'struct' => $this->structFields($def, $forceOptional, $skip),
            'merged' => array_diff_key(
                array_map(fn (array $f): array => $forceOptional ? $this->optionalField($f) : $f, $this->mergedFields($def)),
                array_flip($skip),
            ),
            default => throw new RuntimeException("Cannot flatten {$name} ({$this->kinds[$name]})"),
        };
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $def */
    private function renderEnum(string $name, array $def): string
    {
        $backing = $this->enumBacking($def);
        $cases = [];
        foreach ($def['oneOf'] ?? $def['anyOf'] as $variant) {
            if (! array_key_exists('const', $variant)) {
                continue;
            }
            $case = $this->pascal(is_int($variant['const']) ? ($variant['title'] ?? 'Code' . abs($variant['const'])) : $variant['const']);
            $cases[] = $this->doc($variant['description'] ?? '', 4)
                . '    case ' . $case . ' = ' . var_export($variant['const'], true) . ';';
        }

        $open = $backing === 'int' ? 'int' : 'string';

        return $this->file(
            self::NS,
            [],
            $this->doc(($def['description'] ?? '') . "\n\nOpen enum: values unknown to this SDK version are kept as raw {$open}s.")
            . "enum {$name}: {$backing}\n{\n" . implode("\n\n", $cases) . "\n}\n",
        );
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $def
     */
    private function renderStruct(string $name, array $fields, array $def): string
    {
        return $this->file(
            self::NS,
            ['Acp\\Support\\Cast', 'JsonSerializable', 'stdClass'],
            $this->doc($def['description'] ?? '')
            . "final readonly class {$name} implements JsonSerializable\n{\n"
            . $this->renderBody($fields, null, null)
            . "}\n",
        );
    }

    /**
     * @param array<string, mixed> $def
     *
     * @return array<string, string>
     */
    private function renderUnion(string $name, array $def): array
    {
        $variants = $def['oneOf'] ?? $def['anyOf'];
        $tag = $def['discriminator']['propertyName'] ?? null;
        if ($tag === null) {
            foreach ($variants as $variant) {
                foreach ($variant['properties'] ?? [] as $property => $schema) {
                    if (array_key_exists('const', $schema)) {
                        $tag = $property;
                        break 2;
                    }
                }
            }
        }

        // Build variant models.
        $models = [];
        foreach ($variants as $variant) {
            $value = $tag !== null ? ($variant['properties'][$tag]['const'] ?? null) : null;
            if ($value === null && ($variant['title'] ?? null) === 'other') {
                continue; // covered by Unrecognized
            }
            $refs = array_map(fn (array $part): string => $this->refName($part['$ref']), $variant['allOf'] ?? []);
            $fields = $this->structFields($variant, skip: $tag !== null ? [$tag] : []);
            foreach ($refs as $ref) {
                $fields += $this->flatten($ref, skip: $tag !== null ? [$tag] : []);
            }
            $models[] = [
                'tag' => $value,
                'title' => $variant['title'] ?? null,
                'refs' => $refs,
                'fields' => $fields,
                'description' => $variant['description'] ?? '',
            ];
        }

        // Class names: from the tag value, else the title; fall back to the referenced definition
        // when a tag-derived name would be a reserved word.
        $useRefs = false;
        foreach ($models as $model) {
            if ($model['tag'] !== null && in_array(strtolower($this->pascal($model['tag'])), self::RESERVED, true)) {
                $useRefs = true;
            }
        }
        foreach ($models as &$model) {
            if ($useRefs && count($model['refs']) === 1) {
                $model['class'] = $model['refs'][0];
            } elseif ($model['tag'] !== null) {
                $model['class'] = $this->pascal($model['tag']);
            } else {
                $model['class'] = $this->pascal($model['title'] ?? $model['refs'][0] ?? 'Default');
            }
            if (in_array(strtolower($model['class']), self::RESERVED, true)) {
                $model['class'] .= 'Variant';
            }
        }
        unset($model);

        $namespace = self::NS . '\\' . $name;
        $files = [];

        // Abstract base with the dispatcher.
        $arms = [];
        $default = null;
        $untagged = [];
        foreach ($models as $model) {
            if ($model['tag'] !== null) {
                $arms[] = "            {$name}\\{$model['class']}::TAG => {$name}\\{$model['class']}::fromArray(\$data),";
            } elseif ($tag !== null) {
                $default = "{$name}\\{$model['class']}";
            } else {
                $required = array_keys(array_filter($model['fields'], static fn (array $f): bool => $f['required']));
                $untagged[] = [$model['class'], $required];
            }
        }

        if ($tag !== null) {
            if ($default !== null) {
                $arms[] = "            null => {$default}::fromArray(\$data),";
            }
            $arms[] = "            default => {$name}\\Unrecognized::fromArray(\$data),";
            $dispatch = "        return match (\$data[self::DISCRIMINATOR] ?? null) {\n" . implode("\n", $arms) . "\n        };\n";
            $constant = '    public const DISCRIMINATOR = ' . var_export($tag, true) . ";\n\n";
        } else {
            usort($untagged, static fn (array $a, array $b): int => count($b[1]) <=> count($a[1]));
            $dispatch = '';
            foreach ($untagged as [$class, $required]) {
                $conditions = array_map(static fn (string $key): string => 'array_key_exists(' . var_export($key, true) . ', $data)', $required);
                $dispatch .= '        if (' . ($conditions === [] ? 'true' : implode(' && ', $conditions)) . ") {\n"
                    . "            return {$name}\\{$class}::fromArray(\$data);\n        }\n\n";
            }
            $dispatch .= "        return {$name}\\Unrecognized::fromArray(\$data);\n";
            $constant = '';
        }

        $variantList = implode("\n", array_map(
            static fn (array $m): string => " * - {$name}\\{$m['class']}" . ($m['tag'] !== null ? " ({$tag}: \"{$m['tag']}\")" : ''),
            $models,
        )) . "\n * - {$name}\\Unrecognized (anything this SDK version does not know yet)";

        $files[$name . '.php'] = $this->file(
            self::NS,
            ['JsonSerializable'],
            $this->doc(($def['description'] ?? '') . "\n\nVariants:\n" . str_replace(' * ', '', $variantList))
            . "abstract readonly class {$name} implements JsonSerializable\n{\n"
            . $constant
            . "    /**\n     * @param array<string, mixed> \$data\n     */\n"
            . "    public static function fromArray(array \$data): self\n    {\n"
            . $dispatch
            . "    }\n}\n",
        );

        foreach ($models as $model) {
            $uses = ['Acp\\Support\\Cast', self::NS . '\\' . $name, 'stdClass'];
            $body = '';
            if ($model['tag'] !== null) {
                $body .= '    public const TAG = ' . var_export($model['tag'], true) . ";\n\n";
            }
            $body .= $this->renderBody($model['fields'], $model['tag'] !== null ? $tag : null, $namespace);
            $files[$name . '/' . $model['class'] . '.php'] = $this->file(
                $namespace,
                array_merge($uses, $this->referencedClasses($model['fields'], $namespace, $model['class'])),
                $this->doc($model['description']) . "final readonly class {$model['class']} extends {$name}\n{\n{$body}}\n",
            );
        }

        $files[$name . '/Unrecognized.php'] = $this->file(
            $namespace,
            [self::NS . '\\' . $name, 'stdClass'],
            $this->doc("A {$name} variant this SDK version does not know.\n\nThe raw payload is kept untouched so it can be inspected or forwarded.")
            . "final readonly class Unrecognized extends {$name}\n{\n"
            . "    /**\n     * @param array<string, mixed> \$data\n     */\n"
            . "    public function __construct(\n        public array \$data,\n    ) {\n    }\n\n"
            . "    /**\n     * @param array<string, mixed> \$data\n     */\n"
            . "    public static function fromArray(array \$data): static\n    {\n        return new static(\$data);\n    }\n\n"
            . ($tag !== null ? "    public function tag(): mixed\n    {\n        return \$this->data[self::DISCRIMINATOR] ?? null;\n    }\n\n" : '')
            . "    public function jsonSerialize(): stdClass\n    {\n        return (object) \$this->data;\n    }\n}\n",
        );

        return $files;
    }

    /**
     * Constructor, fromArray() and jsonSerialize().
     *
     * @param array<string, array<string, mixed>> $fields
     */
    private function renderBody(array $fields, ?string $tag, ?string $namespace): string
    {
        $phpNames = [];
        foreach ($fields as $field) {
            if (isset($phpNames[$field['php']])) {
                throw new RuntimeException("Duplicate PHP property {$field['php']}");
            }
            $phpNames[$field['php']] = true;
        }

        // Required parameters first, then optional ones.
        $ordered = array_merge(
            array_filter($fields, static fn (array $f): bool => $f['required']),
            array_filter($fields, static fn (array $f): bool => ! $f['required']),
        );

        $docs = [];
        $params = [];
        $hydrations = [];
        $serialize = [];

        foreach ($ordered as $field) {
            $type = $this->phpType($field['type'], $field['nullable']);
            $docType = $this->docType($field['type'], $field['nullable']);
            $summary = $this->summary($field['description']);
            $docs[] = '     * @param ' . $docType . ' $' . $field['php'] . ($summary !== '' ? ' ' . $summary : '');

            $params[] = '        public ' . $type . ' $' . $field['php'] . ($field['default'] !== null ? ' = ' . $field['default'] : '') . ',';

            $json = var_export($field['json'], true);
            if ($field['required']) {
                $raw = "Cast::required(\$data, {$json})";
                $expr = $field['nullable']
                    ? "Cast::optional({$raw}, static fn (mixed \$value) => " . $this->hydrate($field['type'], '$value', $field['json'], $field['skipInvalid']) . ')'
                    : $this->hydrate($field['type'], $raw, $field['json'], $field['skipInvalid']);
            } else {
                $expr = "Cast::optional(\$data[{$json}] ?? null, static fn (mixed \$value) => "
                    . $this->hydrate($field['type'], '$value', $field['json'], $field['skipInvalid'])
                    . ($field['hydrateDefault'] !== 'null' ? ', ' . $field['hydrateDefault'] : '') . ')';
                if ($field['lenient']) {
                    $expr = "Cast::lenient(static fn () => {$expr}, {$field['hydrateDefault']})";
                }
            }
            $hydrations[] = "            {$field['php']}: {$expr},";

            $value = "\$this->{$field['php']}";
            if (in_array($field['type']['t'], ['map', 'object'], true)) {
                $value = $field['nullable'] ? "{$value} === null ? null : Cast::jsonObject({$value})" : "Cast::jsonObject({$value})";
            }
            $assign = "\$data[{$json}] = {$value};";
            if ($field['omitIf'] !== null && ! $field['required']) {
                $guard = "\$this->{$field['php']} !== {$field['omitIf']}"
                    . (isset($field['omitDefault']) ? " && \$this->{$field['php']} != {$field['omitDefault']}" : '');
                $serialize[] = "        if ({$guard}) {\n            \$data[{$json}] = " . (in_array($field['type']['t'], ['map', 'object'], true) ? "Cast::jsonObject(\$this->{$field['php']})" : "\$this->{$field['php']}") . ";\n        }";
            } else {
                $serialize[] = "        {$assign}";
            }
        }

        $out = '';
        if ($params !== []) {
            $out .= "    /**\n" . implode("\n", $docs) . "\n     */\n";
            $out .= "    public function __construct(\n" . implode("\n", $params) . "\n    ) {\n    }\n\n";
        }

        $out .= "    /**\n     * @param array<string, mixed> \$data\n     */\n";
        $out .= "    public static function fromArray(array \$data): static\n    {\n";
        $out .= $hydrations === []
            ? "        return new static();\n"
            : "        return new static(\n" . implode("\n", $hydrations) . "\n        );\n";
        $out .= "    }\n\n";

        $out .= "    public function jsonSerialize(): stdClass\n    {\n";
        $out .= $tag !== null
            ? '        $data = [' . var_export($tag, true) . " => self::TAG];\n"
            : "        \$data = [];\n";
        if ($serialize !== []) {
            $out .= implode("\n", $serialize) . "\n";
        }
        $out .= "\n        return (object) \$data;\n    }\n";

        return $out;
    }

    /**
     * Root-namespace classes referenced by fields of a class living in a sub-namespace.
     *
     * @param array<string, array<string, mixed>> $fields
     *
     * @return list<string>
     */
    private function referencedClasses(array $fields, string $namespace, string $self): array
    {
        $names = [];
        $collect = function (array $type) use (&$collect, &$names): void {
            if (in_array($type['t'], ['class', 'enum'], true)) {
                $names[] = $type['name'];
            }
            if (isset($type['item'])) {
                $collect($type['item']);
            }
        };
        foreach ($fields as $field) {
            $collect($field['type']);
        }
        foreach ($fields as $field) {
            if ($field['default'] !== null && preg_match('/^(\w+)::/', $field['default'], $m)) {
                $names[] = $m[1];
            }
            if (preg_match('/^(\w+)::/', $field['hydrateDefault'], $m)) {
                $names[] = $m[1];
            }
        }

        $uses = [];
        foreach (array_unique($names) as $name) {
            if ($name === $self) {
                throw new RuntimeException("Variant {$namespace}\\{$self} references a root class with the same name");
            }
            $uses[] = self::NS . '\\' . $name;
        }

        return $uses;
    }

    /** @param array<string, string> $methods */
    private function renderMethods(string $enum, array $methods): string
    {
        $cases = [];
        $params = [];
        $results = [];
        foreach ($methods as $key => $method) {
            $case = $this->pascal($key);
            $cases[] = "    case {$case} = " . var_export($method, true) . ';';
            [$request, $response] = $this->methodDefinitions($method);
            $params[] = "            self::{$case} => " . ($request !== null ? "{$request}::class" : 'null') . ',';
            $results[] = "            self::{$case} => " . ($response !== null ? "{$response}::class" : 'null') . ',';
        }

        $side = match ($enum) {
            'AgentMethod' => 'Methods handled by the agent (called by the client).',
            'ClientMethod' => 'Methods handled by the client (called by the agent).',
            default => 'Protocol-level methods, usable by both sides.',
        };

        $signature = static function (array $arms): array {
            $nulls = count(array_filter($arms, static fn (string $arm): bool => str_ends_with($arm, '=> null,')));

            return match (true) {
                $nulls === 0 => ['string', 'class-string'],
                $nulls === count($arms) => ['null', 'null'],
                default => ['?string', 'class-string|null'],
            };
        };
        [$paramsType, $paramsDoc] = $signature($params);
        [$resultType, $resultDoc] = $signature($results);

        return $this->file(
            self::NS,
            [],
            $this->doc($side)
            . "enum {$enum}: string\n{\n" . implode("\n", $cases) . "\n\n"
            . "    /**\n     * Class of the request or notification params.\n     *\n     * @return {$paramsDoc}\n     */\n"
            . "    public function paramsClass(): {$paramsType}\n    {\n        return match (\$this) {\n" . implode("\n", $params) . "\n        };\n    }\n\n"
            . "    /**\n     * Class of the response result, null for notifications.\n     *\n     * @return {$resultDoc}\n     */\n"
            . "    public function resultClass(): {$resultType}\n    {\n        return match (\$this) {\n" . implode("\n", $results) . "\n        };\n    }\n\n"
            . "    public function isNotification(): bool\n    {\n        return " . ($resultType === 'null' ? 'true' : '$this->resultClass() === null') . ";\n    }\n}\n",
        );
    }

    /** @return array{0: ?string, 1: ?string} */
    private function methodDefinitions(string $method): array
    {
        $request = null;
        $response = null;
        foreach ($this->defs as $name => $def) {
            if (($def['x-method'] ?? null) !== $method || $this->kinds[$name] === 'skip') {
                continue;
            }
            if (str_ends_with($name, 'Response')) {
                $response = $name;
            } else {
                $request = $name;
            }
        }

        return [$request, $response];
    }

    private function renderVersion(): string
    {
        $tag = var_export($this->release, true);

        return $this->file(
            self::NS,
            [],
            $this->doc('Version of the ACP schema these classes were generated from.')
            . "final class SchemaVersion\n{\n"
            . "    /** Release of agentclientprotocol/agent-client-protocol the schema comes from. */\n"
            . "    public const RELEASE = {$tag};\n\n"
            . "    /** Protocol version negotiated during initialize. */\n"
            . "    public const PROTOCOL_VERSION = " . var_export($this->meta['version'], true) . ";\n}\n",
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param list<string> $uses */
    private function file(string $namespace, array $uses, string $body): string
    {
        $uses = array_values(array_unique(array_filter(
            $uses,
            static fn (string $use): bool => substr($use, 0, (int) strrpos($use, '\\')) !== $namespace,
        )));
        sort($uses);

        return "<?php\n\n"
            . "// This file is generated by bin/generate-schema.php. Do not edit it by hand.\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace {$namespace};\n\n"
            . ($uses !== [] ? implode("\n", array_map(static fn (string $u): string => "use {$u};", $uses)) . "\n\n" : '')
            . $body;
    }

    private function doc(string $text, int $indent = 0): string
    {
        $text = trim(str_replace('*/', '*\\/', $text));
        if ($text === '') {
            return '';
        }
        $pad = str_repeat(' ', $indent);
        $lines = array_map(
            static fn (string $line): string => rtrim($pad . ' * ' . $line),
            explode("\n", $text),
        );

        return $pad . "/**\n" . implode("\n", $lines) . "\n" . $pad . " */\n";
    }

    private function summary(string $description): string
    {
        $first = trim(explode("\n\n", trim($description))[0]);
        $first = preg_replace('/\s+/', ' ', $first) ?? '';
        if (preg_match('/^(.+?\.)(\s|$)/', $first, $m) === 1) {
            $first = $m[1];
        }
        if (preg_match('/^(.+?\.)(\s|$)/', $first, $m)) {
            $first = $m[1];
        }

        return str_replace('*/', '*\\/', $first);
    }

    private function pascal(string $value): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $value)) {
            return ucfirst($value);
        }
        $parts = preg_split('/[^A-Za-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: ['Value'];
        $name = implode('', array_map(static fn (string $p): string => ucfirst($p), $parts));

        return ctype_digit($name[0]) ? 'V' . $name : $name;
    }

    /** @param array<array-key, mixed> $value */
    private function exportArray(array $value): string
    {
        if ($value === []) {
            return '[]';
        }
        $items = [];
        foreach ($value as $key => $item) {
            $items[] = var_export($key, true) . ' => ' . (is_array($item) ? $this->exportArray($item) : var_export($item, true));
        }

        return '[' . implode(', ', $items) . ']';
    }
}
