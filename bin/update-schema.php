#!/usr/bin/env php
<?php

/**
 * Downloads a release of the official ACP schema into resources/schema/v1.
 *
 *   php bin/update-schema.php                  latest release
 *   php bin/update-schema.php schema-v1.35.0   a given release
 *
 * Then run `composer generate` and review the diff of src/Schema.
 */

declare(strict_types=1);

$repository = 'https://github.com/agentclientprotocol/agent-client-protocol/releases';
$tag = $argv[1] ?? null;
$target = dirname(__DIR__) . '/resources/schema/v1';

if ($tag === null) {
    // The "latest" download URL redirects to the tagged one, which tells us the release name.
    $headers = get_headers($repository . '/latest/download/schema.json', true) ?: [];
    $location = (array) ($headers['Location'] ?? $headers['location'] ?? []);
    if (preg_match('#/download/([^/]+)/schema\.json$#', (string) reset($location), $m) !== 1) {
        fwrite(STDERR, "Could not resolve the latest release, pass a tag explicitly.\n");
        exit(1);
    }
    $tag = $m[1];
}

foreach (['schema.json', 'meta.json'] as $file) {
    $content = file_get_contents("{$repository}/download/{$tag}/{$file}");
    if ($content === false) {
        fwrite(STDERR, "Could not download {$file} for {$tag}.\n");
        exit(1);
    }
    json_decode($content, flags: JSON_THROW_ON_ERROR);
    file_put_contents("{$target}/{$file}", $content);
}

file_put_contents("{$target}/VERSION", $tag . "\n");
echo "Schema updated to {$tag}. Run `composer generate` and review src/Schema.\n";
