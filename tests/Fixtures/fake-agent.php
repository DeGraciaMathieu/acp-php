<?php

// A FakeAgent served over stdio, run as a real subprocess by AgentProcessTest.

declare(strict_types=1);

use Acp\Schema\ToolKind;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[1] ?? 'normal';

fwrite(STDERR, "fake agent started in {$mode} mode\n");

if ($mode === 'crash') {
    fwrite(STDERR, "fatal: missing credentials\n");
    exit(3);
}

FakeAgent::create()
    ->onPrompt(function (FakeTurn $turn): null {
        fwrite(STDERR, 'prompt: ' . $turn->prompt() . "\n");
        $turn->toolCall('call_1', 'Read ' . getcwd(), ToolKind::Read);
        $turn->say('Hello from PID ' . getmypid() . ', env ACP_TEST=' . (getenv('ACP_TEST') ?: 'unset'));

        return null;
    })
    ->runOverStdio();
