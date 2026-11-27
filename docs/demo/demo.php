<?php

// Drives Claude Code on a project, deciding its permissions live: edits are allowed, commands refused.
// Recorded by record.sh into docs/demo.gif.
//   php docs/demo/demo.php <project-dir> "<prompt>"

declare(strict_types=1);

use Acp\AgentProcess;
use Acp\Client\Permission\{CallbackPermissions, Outcome};
use Acp\Schema\ContentBlock\TextContent;
use Acp\Schema\{RequestPermissionRequest, SessionUpdate, ToolCallStatus, ToolKind};

require __DIR__ . '/../../vendor/autoload.php';

$project = realpath($argv[1] ?? '/nonexistent') ?: exit("Usage: php docs/demo/demo.php <project-dir> \"<prompt>\"\n");
$prompt = $argv[2] ?? 'tests/CartTest.php fails. Fix it.';

// Claude sends a generic title first ("Read File"), then the detailed one in an update.
/** @var array<string, string> $titles toolCallId => latest title, relative to the project */
$titles = [];
$lineStarted = false;
$show = function (string $toolCallId, string $decision = '') use (&$titles, &$lineStarted): void {
    echo ($lineStarted ? "\n" : '') . "  \e[2m→\e[0m " . ($titles[$toolCallId] ?? $toolCallId) . "{$decision}\n";
    unset($titles[$toolCallId]);
    $lineStarted = false;
};

$agent = AgentProcess::start('claude-agent-acp', cwd: $project, env: ['ANTHROPIC_API_KEY' => null]);

$client = $agent->connect(permissions: new CallbackPermissions(function (RequestPermissionRequest $request) use ($show) {
    $refused = $request->toolCall->kind === ToolKind::Execute;
    $show($request->toolCall->toolCallId, $refused ? "  \e[31m✗ refused\e[0m" : "  \e[32m✓ allowed\e[0m");

    return $refused ? Outcome::reject($request) : Outcome::approve($request);
}));
$client->initialize();

$session = $client->newSession();
// Claude may be set to act without asking: make it ask, so that every action reaches the handler.
$session->setMode('default');

// The language instruction overrides a user-level CLAUDE.md that may ask for another one.
$turn = $session->prompt($prompt . ' Answer in English, in two sentences.');
foreach ($turn->updates() as $update) {
    if (($update instanceof SessionUpdate\ToolCall || $update instanceof SessionUpdate\ToolCallUpdate) && (string) $update->title !== '') {
        $titles[$update->toolCallId] = str_replace($project . '/', '', $update->title);
    }
    if ($update instanceof SessionUpdate\ToolCallUpdate && $update->status === ToolCallStatus::Completed && isset($titles[$update->toolCallId])) {
        $show($update->toolCallId);
    }
    if ($update instanceof SessionUpdate\AgentMessageChunk && $update->content instanceof TextContent) {
        echo $update->content->text;
        $lineStarted = ! str_ends_with($update->content->text, "\n");
    }
}

echo "\n";
$agent->stop();
