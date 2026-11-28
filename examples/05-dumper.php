<?php

// Prints every JSON-RPC message of a turn, permission exchange included.
//   php examples/05-dumper.php                 (messages on STDERR, answer on STDOUT)
//   php examples/05-dumper.php 2> acp.log      (messages in a file)

declare(strict_types=1);

use Acp\Client\Permission\ApproveAll;
use Acp\Rpc\Dumper;
use Acp\Schema\ToolCallStatus;
use Acp\Schema\ToolKind;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;

require __DIR__ . '/../vendor/autoload.php';

$client = FakeAgent::create()
    ->onPrompt(function (FakeTurn $turn): null {
        $turn->toolCall('call_1', 'npm test', ToolKind::Execute);
        $turn->requestPermission('call_1', 'npm test');
        $turn->updateToolCall('call_1', ToolCallStatus::Completed, 'All green.');
        $turn->say('The tests pass.');

        return null;
    })
    ->connect(permissions: new ApproveAll(), onMessage: new Dumper());

$client->initialize();
$turn = $client->newSession(cwd: getcwd() ?: '/tmp')->prompt('Run the tests');

echo $turn->text() . "\n";
