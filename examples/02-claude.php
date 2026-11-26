<?php

// Drives the real Claude agent (npm i -g @agentclientprotocol/claude-agent-acp).
//   php examples/02-claude.php <dir> "<prompt>" [--approve-all] [--timeout=SECONDS] [--verbose]
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

$dir = realpath($argv[1] ?? '') ?: exit("Usage: php examples/02-claude.php <dir> \"<prompt>\" [--approve-all] [--timeout=SECONDS] [--verbose]\n");
$prompt = $argv[2] ?? 'Liste les fichiers de ce dossier et résume ce que contient le projet en trois phrases.';
$options = array_slice($argv, 3);
$approveAll = in_array('--approve-all', $options, true);
$verbose = in_array('--verbose', $options, true);
$timeout = null;
foreach ($options as $option) {
    if (str_starts_with($option, '--timeout=')) {
        $timeout = (float) substr($option, strlen('--timeout='));
    }
}

$permissions = $approveAll ? new ApproveAll() : new CallbackPermissions(function (RequestPermissionRequest $request) {
    $kind = $request->toolCall->kind;
    $allowed = $kind === ToolKind::Read || $kind === ToolKind::Search;
    echo "\n  🔐 Permission demandée : {$request->toolCall->title} → " . ($allowed ? 'acceptée' : 'refusée') . "\n";

    return $allowed ? Outcome::approve($request) : Outcome::reject($request);
});

$agent = AgentProcess::start(
    'claude-agent-acp',
    cwd: $dir,
    env: ['ANTHROPIC_API_KEY' => null],
    onStderr: $verbose ? function (string $line): void {
        fwrite(STDERR, "[agent] {$line}\n");
    } : null,
);

try {
    $client = $agent->connect(permissions: $permissions);
    $init = $client->initialize();
    echo "Agent : {$init->agentInfo?->name} {$init->agentInfo?->version}\n";

    $session = $client->newSession(cwd: $dir);

    // Claude starts in "auto" mode, where it may act without asking. Without --approve-all,
    // switch to "default" (Manual: always ask) so that every action goes through the handler.
    if (! $approveAll && $session->modes()?->currentModeId !== 'default') {
        $session->setMode('default');
    }
    echo "Session : {$session->id} (mode {$session->modes()?->currentModeId})\n\n";

    $turn = $session->prompt($prompt, $timeout !== null ? new TimeoutCancellation($timeout) : null);
    foreach ($turn->updates() as $update) {
        printUpdate($update);
    }

    echo "\n\nFin du tour : " . json_encode($turn->stopReason()) . "\n";
    echo "Pour reprendre : php examples/03-resume.php {$dir} {$session->id} \"<prompt>\"\n";
} catch (RpcError $e) {
    echo "\nErreur de l'agent ({$e->getCode()}) : {$e->getMessage()}\n";
    if ($e->isAuthRequired()) {
        echo "→ Lance `claude`, puis /login, et réessaie.\n";
    }
} catch (ConnectionClosed $e) {
    echo "\nL'agent s'est arrêté. Ses derniers logs :\n  " . implode("\n  ", array_slice($agent->stderr(), -10)) . "\n";
} finally {
    $agent->stop();
}
