# Driving an agent

```php
use Acp\AgentProcess;
use Acp\Client\FileSystem\LocalFileSystem;
use Acp\Client\Permission\ApproveAll;
use Acp\Schema\SessionUpdate;

$agent = AgentProcess::start(
    'claude-agent-acp',                        // npm i -g @agentclientprotocol/claude-agent-acp
    cwd: '/home/me/game',
    env: ['ANTHROPIC_API_KEY' => null],        // use the Claude Code login, not API billing
);

$client = $agent->connect(
    permissions: new ApproveAll(),             // default: reject everything
    fileSystem: new LocalFileSystem('/home/me/game'), // optional: serve file reads and writes, confined to a root
);
$client->initialize();

$session = $client->newSession(cwd: '/home/me/game');
$turn = $session->prompt('Switch the game to a dark theme');

foreach ($turn->updates() as $update) {
    if ($update instanceof SessionUpdate\ToolCall) {
        echo "→ {$update->title}\n";
    }
}

$turn->stopReason();             // StopReason::EndTurn
echo $turn->text();              // the agent's answer, as plain text

$agent->stop();
```

The API reads synchronously, but runs on [amphp](https://amphp.org) fibers: while your code waits on a turn, the SDK keeps reading the agent's messages and answering its requests.

## Turns

- `$session->prompt()` returns at once. Read `$turn->updates()` as they stream, or just wait with `stopReason()`, `text()` or `wait()`: updates are buffered, reading them is optional.
- `$turn->cancel()` (or a cancellation passed to `prompt()`) cancels the ACP way: the agent ends the turn with the `cancelled` stop reason, and pending permission requests are answered with the `cancelled` outcome right away, even if your handler is still waiting.
- One turn at a time per session, as in ACP v1 (`TurnInProgress` otherwise).
- `$session->onUpdate()` sees every update, inside turns or not, to log or persist a session.
- `$client->loadSession($id, cwd: …)` resumes a session; the replayed conversation is in `$session->history()`.

## Permissions

Implement `PermissionHandler`, or use `ApproveAll`, `RejectAll` or `CallbackPermissions`:

```php
use Acp\Client\Permission\{CallbackPermissions, Outcome};
use Acp\Schema\{RequestPermissionRequest, ToolKind};

$permissions = new CallbackPermissions(fn (RequestPermissionRequest $request) => match ($request->toolCall->kind) {
    ToolKind::Read, ToolKind::Edit => Outcome::approve($request),
    default => Outcome::reject($request),
});
```

A handler may wait as long as it needs (for a human, on Telegram): the session keeps flowing meanwhile.

## Errors

- `RpcError`: the agent answered with an error. `$e->isAuthRequired()` for `-32000`, then `$client->authenticate($methodId)`.
- `ConnectionClosed`: the agent exited. `$agent->stderr()` keeps its last lines.
- `InvalidPayload`: a message does not match the schema.

## Debugging

`$turn->updates()` only shows what the agent streams. To see every JSON-RPC message, permission requests and file reads included, pass a `Dumper` to `connect()`:

```php
use Acp\Rpc\Dumper;

$client = $agent->connect(permissions: new ApproveAll(), onMessage: new Dumper());
```

It prints one line per message on STDERR, `→` for what the client sends and `←` for what it receives:

```
→ #2 session/prompt {"sessionId":"sess_1","prompt":[{"type":"text","text":"Run the tests"}]}
← session/update {"sessionId":"sess_1","update":{"sessionUpdate":"tool_call","toolCallId":"call_1",…}}
← #0 session/request_permission {"sessionId":"sess_1","toolCall":{…},"options":[…]}
→ #0 result {"outcome":{"outcome":"selected","optionId":"allow"}}
← #2 result {"stopReason":"end_turn"}
```

`new Dumper(fopen('acp.log', 'a'))` writes to a file instead. `onMessage` takes any `callable(Direction, string): void`, to log the raw messages your own way. See `examples/05-dumper.php`.
