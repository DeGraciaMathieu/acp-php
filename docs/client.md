# Driving an agent

## A first turn

Start the agent, connect to it, open a session and send a prompt:

```php
use Acp\AgentProcess;

$agent = AgentProcess::start('claude-agent-acp', cwd: '/home/me/game');

$client = $agent->connect();
$client->initialize();

$session = $client->newSession();
$turn = $session->prompt('What does this project do?');

echo $turn->text();

$agent->stop();
```

- `AgentProcess::start()` runs the agent as a subprocess, in the project it works on. `claude-agent-acp` is the ACP adapter of Claude Code: `npm i -g @agentclientprotocol/claude-agent-acp`.
- `initialize()` negotiates the protocol version and capabilities. It must come first.
- `newSession()` opens a conversation, in the agent's directory.
- `prompt()` starts a turn: the agent works until it answers. `text()` waits for the end of the turn and returns the answer.

`claude-agent-acp` uses your Claude Code login, unless `ANTHROPIC_API_KEY` is set: then it bills the API. To stay on your login, remove the variable for the agent:

```php
$agent = AgentProcess::start('claude-agent-acp', cwd: '/home/me/game', env: ['ANTHROPIC_API_KEY' => null]);
```

The API reads synchronously, but runs on [amphp](https://amphp.org) fibers: while your code waits on a turn, the SDK keeps reading the agent's messages and answering its requests.

## Following the turn

While it works, the agent streams updates: tool calls, plans, thoughts, pieces of its answer. Read them as they arrive with `updates()`:

```php
use Acp\Schema\SessionUpdate;

$turn = $session->prompt('Fix the failing test');

foreach ($turn->updates() as $update) {
    if ($update instanceof SessionUpdate\ToolCall) {
        echo "→ {$update->title}\n";
    }
}

$turn->stopReason(); // StopReason::EndTurn
```

Reading the updates is optional: they are buffered, and `text()`, `stopReason()` or `wait()` just wait for the end of the turn.

## Permissions

Before it edits a file or runs a command, the agent asks for permission. By default, the client refuses everything: the agent can read, but not act. Pass a `PermissionHandler` to `connect()` to decide.

`CallbackPermissions` decides with a closure. It receives the request: `$request->toolCall` describes the action, with a `title` and a `kind`. `Outcome` builds the answer. Here Claude may read, search and edit, but not run commands or go on the web:

```php
use Acp\Client\Permission\{CallbackPermissions, Outcome};
use Acp\Schema\{RequestPermissionRequest, ToolKind};

$client = $agent->connect(permissions: new CallbackPermissions(
    fn (RequestPermissionRequest $request) => match ($request->toolCall->kind) {
        ToolKind::Read, ToolKind::Search => Outcome::approve($request),
        ToolKind::Edit => Outcome::approve($request),
        ToolKind::Execute, ToolKind::Fetch => Outcome::reject($request),
        default => Outcome::reject($request),
    },
));
```

The kinds Claude Code sends, and its tools behind them:

| Kind | Claude Code tools |
|---|---|
| `ToolKind::Read` | Read |
| `ToolKind::Edit` | Write, Edit |
| `ToolKind::Search` | Grep, Glob |
| `ToolKind::Execute` | Bash, so deleting or moving files too |
| `ToolKind::Fetch` | WebFetch, WebSearch |
| `ToolKind::SwitchMode` | EnterPlanMode, ExitPlanMode: approving a plan |
| `ToolKind::Think` | subagents, tasks |
| `ToolKind::Other` | skills, MCP tools, NotebookEdit |

ACP also defines `ToolKind::Delete` and `ToolKind::Move`, which other agents may send. An agent may send a kind this SDK does not know yet: it then arrives as a string, and lands in `default`.

- `Outcome::approve()` allows once, `Outcome::reject()` refuses once.
- The agent also offers to allow or refuse for good: `Outcome::select($request, PermissionOptionKind::AllowAlways)`.
- `ApproveAll` and `RejectAll` answer every request the same way.

A handler may wait as long as it needs, for a human on Slack for instance: the session keeps flowing meanwhile. For more, implement `PermissionHandler` yourself.

## Modes

Claude Code may be set to act without asking: then your handler never hears from it. A session has modes, which decide how much the agent may do on its own. List them, and pick the one you want:

```php
$session = $client->newSession();

foreach ($session->modes()?->availableModes ?? [] as $mode) {
    echo "{$mode->id}: {$mode->name}\n";
}

$session->setMode('default'); // Claude Code asks before editing or running anything
```

## Files

By default, the agent reads and writes files on its own. Pass a `FileSystemHandler` to `connect()` and it goes through your code instead: to confine it, record its writes, or serve files that are not on disk.

`LocalFileSystem` serves a directory, and refuses any path outside of it:

```php
use Acp\Client\FileSystem\LocalFileSystem;

$client = $agent->connect(fileSystem: new LocalFileSystem('/home/me/game'));
```

## Sessions

A session is a conversation: each prompt sees the previous ones. One turn runs at a time per session, a second `prompt()` meanwhile throws `TurnInProgress`.

```php
$session = $client->newSession();
$session->prompt('Add a dark theme')->wait();
$session->prompt('Now make it the default')->wait();
```

- One agent serves several projects: `$client->newSession(cwd: '/home/me/other')`.
- `$session->onUpdate()` sees every update of the session, inside turns or not: to log or persist it.
- Keep `$session->id` to resume the session later, with a new agent process: `$client->loadSession($id)`. The replayed conversation is in `$session->history()`.

## Cancelling a turn

`$turn->cancel()` asks the agent to stop. It ends the turn with `StopReason::Cancelled`, and any permission request still waiting is answered right away, even if your handler has not decided yet.

To cancel after a delay, pass an amphp `Cancellation` to `prompt()`:

```php
use Amp\TimeoutCancellation;

$turn = $session->prompt('Refactor the whole project', new TimeoutCancellation(60));
```

## Errors

Every exception of the SDK implements `AcpException`:

- `RpcError`: the agent answered with an error.
- `ConnectionClosed`: the agent exited. `$agent->stderr()` keeps its last lines.
- `InvalidPayload`: a message does not match the schema.

When the agent needs a login, creating a session fails with an `RpcError` for which `isAuthRequired()` is true. The ways to log in are listed by `initialize()`:

```php
use Acp\Exception\RpcError;

$methods = $client->initialize()->authMethods;

try {
    $session = $client->newSession();
} catch (RpcError $e) {
    if (! $e->isAuthRequired()) {
        throw $e;
    }
    $client->authenticate($methods[0]->id);
    $session = $client->newSession();
}
```

## Debugging

`$turn->updates()` only shows what the agent streams. To see every JSON-RPC message, permission requests and file reads included, pass a `Dumper` to `connect()`:

```php
use Acp\Rpc\Dumper;

$client = $agent->connect(onMessage: new Dumper());
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
