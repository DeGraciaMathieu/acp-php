<?php

// The host rejects a command: the UI still shows what was refused.
//   php examples/04-permission-denied.php

declare(strict_types=1);

use Acp\Client\Permission\CallbackPermissions;
use Acp\Client\Permission\Outcome;
use Acp\Schema\ContentBlock\TextContent;
use Acp\Schema\RequestPermissionOutcome\Selected;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;
use Acp\Schema\SessionUpdate;
use Acp\Schema\ToolCallStatus;
use Acp\Schema\ToolKind;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;

require __DIR__ . '/../vendor/autoload.php';

// The permission exchange never goes through the update stream, and the protocol does not
// tell the agent what to send after a rejection: the host keeps its own decisions.
/** @var array<string, string> $decisions toolCallId => selected option */
$decisions = [];

$permissions = new CallbackPermissions(function (RequestPermissionRequest $request) use (&$decisions): RequestPermissionResponse {
    $response = $request->toolCall->kind === ToolKind::Execute
        ? Outcome::reject($request)
        : Outcome::approve($request);

    $decisions[$request->toolCall->toolCallId] = $response->outcome instanceof Selected
        ? $response->outcome->optionId
        : 'cancelled';

    return $response;
});

$client = FakeAgent::create()
    ->onPrompt(function (FakeTurn $turn): null {
        $turn->toolCall('call_1', 'Edit theme.css', ToolKind::Edit);
        $turn->requestPermission('call_1', 'Edit theme.css', ToolKind::Edit);
        $turn->updateToolCall('call_1', ToolCallStatus::Completed);

        $turn->toolCall('call_2', 'git push', ToolKind::Execute);
        $choice = $turn->requestPermission('call_2', 'git push', ToolKind::Execute);
        if ($choice === 'reject') {
            $turn->updateToolCall('call_2', ToolCallStatus::Failed, 'Rejected by the host');
            $turn->say('Theme updated. I did not push: the command was rejected.');

            return null;
        }

        $turn->updateToolCall('call_2', ToolCallStatus::Completed);
        $turn->say('Theme updated and pushed.');

        return null;
    })
    ->connect(permissions: $permissions);

$client->initialize();
$session = $client->newSession(cwd: getcwd() ?: '/tmp');
$turn = $session->prompt('Switch the theme to dark and push');

foreach ($turn->updates() as $update) {
    match (true) {
        $update instanceof SessionUpdate\AgentMessageChunk => print($update->content instanceof TextContent ? $update->content->text : ''),
        $update instanceof SessionUpdate\ToolCall => print("\n  ⚙ {$update->title}\n"),
        // By the time the status changes, the host has already decided: merge on toolCallId.
        $update instanceof SessionUpdate\ToolCallUpdate => print(match ($decisions[$update->toolCallId] ?? null) {
            'reject' => "    ✗ rejected by the host\n",
            null => '',
            default => "    ✓ allowed ({$decisions[$update->toolCallId]})\n",
        }),
        default => null,
    };
}

echo "\n\nEnd of turn: " . json_encode($turn->stopReason()) . "\n";
