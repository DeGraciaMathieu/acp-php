<?php

// Drives a real agent: Claude (npm i -g @agentclientprotocol/claude-agent-acp)
// or Codex (npm i -g @zed-industries/codex-acp).
//   php examples/02-agent.php <dir> "<prompt>" [--agent=claude|codex] [--approve-all] [--timeout=SECONDS] [--verbose]
//
// Without --approve-all, reads are approved and everything else is refused.

declare(strict_types=1);

use Acp\AgentProcess;
use Acp\Client\Permission\ApproveAll;
use Acp\Client\Permission\CallbackPermissions;
use Acp\Client\Permission\Outcome;
use Acp\Exception\ConnectionClosed;
use Acp\Exception\RpcError;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\ToolKind;
use Amp\TimeoutCancellation;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_print.php';

$dir = realpath($argv[1] ?? '') ?: exit("Usage: php examples/02-agent.php <dir> \"<prompt>\" [--agent=claude|codex] [--approve-all] [--timeout=SECONDS] [--verbose]\n");
$prompt = $argv[2] ?? 'List the files in this directory and summarise the project in three sentences.';
$options = array_slice($argv, 3);
$approveAll = in_array('--approve-all', $options, true);
$verbose = in_array('--verbose', $options, true);
$timeout = null;
$agentName = 'claude';
foreach ($options as $option) {
    if (str_starts_with($option, '--timeout=')) {
        $timeout = (float) substr($option, strlen('--timeout='));
    }
    if (str_starts_with($option, '--agent=')) {
        $agentName = substr($option, strlen('--agent='));
    }
}

// askMode: the mode in which the agent asks before every action, used without --approve-all.
$agents = [
    'claude' => [
        'command' => 'claude-agent-acp',
        'env' => ['ANTHROPIC_API_KEY' => null],
        'askMode' => 'default',
        'login' => 'Run `claude`, then /login, and try again.',
    ],
    'codex' => [
        'command' => 'codex-acp',
        'env' => [],
        'askMode' => 'read-only',
        'login' => 'Run `codex login` or set OPENAI_API_KEY, and try again.',
    ],
];
$config = $agents[$agentName] ?? exit("Unknown agent: {$agentName} (claude or codex)\n");

$permissions = $approveAll ? new ApproveAll() : new CallbackPermissions(function (RequestPermissionRequest $request) {
    $kind = $request->toolCall->kind;
    $allowed = $kind === ToolKind::Read || $kind === ToolKind::Search;
    echo "\n  🔐 Permission requested: {$request->toolCall->title} → " . ($allowed ? 'approved' : 'rejected') . "\n";

    return $allowed ? Outcome::approve($request) : Outcome::reject($request);
});

$agent = AgentProcess::start(
    $config['command'],
    cwd: $dir,
    env: $config['env'],
    onStderr: $verbose ? function (string $line): void {
        fwrite(STDERR, "[agent] {$line}\n");
    } : null,
);

try {
    $client = $agent->connect(permissions: $permissions);
    $init = $client->initialize();
    echo "Agent: {$init->agentInfo?->name} {$init->agentInfo?->version}\n";

    $session = $client->newSession(cwd: $dir);

    // Agents may start in a mode where they act without asking. Without --approve-all,
    // switch to the agent's ask mode so that every action goes through the handler.
    if (! $approveAll && $session->modes()?->currentModeId !== $config['askMode']) {
        $session->setMode($config['askMode']);
    }
    echo "Session: {$session->id} (mode {$session->modes()?->currentModeId})\n\n";

    $turn = $session->prompt($prompt, $timeout !== null ? new TimeoutCancellation($timeout) : null);
    foreach ($turn->updates() as $update) {
        printUpdate($update);
    }

    echo "\n\nEnd of turn: " . json_encode($turn->stopReason()) . "\n";
    if ($agentName === 'claude') {
        echo "To resume: php examples/03-resume.php {$dir} {$session->id} \"<prompt>\"\n";
    }
} catch (RpcError $e) {
    echo "\nAgent error ({$e->getCode()}): {$e->getMessage()}\n";
    if ($e->isAuthRequired()) {
        echo "→ {$config['login']}\n";
    }
} catch (ConnectionClosed $e) {
    echo "\nThe agent stopped. Its last logs:\n  " . implode("\n  ", array_slice($agent->stderr(), -10)) . "\n";
} finally {
    $agent->stop();
}
