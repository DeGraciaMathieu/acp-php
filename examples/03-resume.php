<?php

// Resumes a Claude session created by 02-agent.php.
//   php examples/03-resume.php <dir> <session-id> "<prompt>"

declare(strict_types=1);

use Acp\AgentProcess;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_print.php';

[$dir, $sessionId, $prompt] = [realpath($argv[1] ?? '') ?: '', $argv[2] ?? '', $argv[3] ?? 'Remind me what I asked you earlier.'];
if ($dir === '' || $sessionId === '') {
    exit("Usage: php examples/03-resume.php <dir> <session-id> \"<prompt>\"\n");
}

$agent = AgentProcess::start('claude-agent-acp', cwd: $dir, env: ['ANTHROPIC_API_KEY' => null]);
try {
    $client = $agent->connect();
    $client->initialize();

    $session = $client->loadSession($sessionId, cwd: $dir);
    echo 'History replayed: ' . count($session->history()) . " updates\n\n";

    $turn = $session->prompt($prompt);
    foreach ($turn->updates() as $update) {
        printUpdate($update);
    }
    echo "\n\nEnd of turn: " . json_encode($turn->stopReason()) . "\n";
} finally {
    $agent->stop();
}
