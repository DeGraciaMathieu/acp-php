<?php

// No real agent needed: a FakeAgent in memory.
//   php examples/01-fake-agent.php

declare(strict_types=1);

use Acp\Client\Permission\ApproveAll;
use Acp\Schema\ToolCallStatus;
use Acp\Schema\ToolKind;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_print.php';

$client = FakeAgent::create()
    ->onPrompt(function (FakeTurn $turn): null {
        $turn->plan('Read the CSS', 'Darken the colours');
        $turn->toolCall('call_1', 'npm test', ToolKind::Execute);
        $choice = $turn->requestPermission('call_1', 'npm test');
        $turn->updateToolCall('call_1', ToolCallStatus::Completed);
        $turn->say("Permission: {$choice}. Theme switched to dark.");

        return null;
    })
    ->connect(permissions: new ApproveAll());

$init = $client->initialize();
echo "Agent: {$init->agentInfo?->name}\n";

$session = $client->newSession(cwd: getcwd() ?: '/tmp');
$turn = $session->prompt('Switch the theme to dark');

foreach ($turn->updates() as $update) {
    printUpdate($update);
}

echo "\n\nEnd of turn: " . json_encode($turn->stopReason()) . "\n";
