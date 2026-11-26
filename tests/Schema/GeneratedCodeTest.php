<?php

declare(strict_types=1);

namespace Acp\Tests\Schema;

use PHPUnit\Framework\TestCase;

final class GeneratedCodeTest extends TestCase
{
    public function test_src_schema_matches_the_bundled_json_schema(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/generate-schema.php') . ' --check 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
    }
}
