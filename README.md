# acp-php

A PHP SDK for the [Agent Client Protocol](https://agentclientprotocol.com) (ACP), the open protocol between clients (editors, orchestrators, CI) and coding agents such as Claude Code, Codex or Gemini CLI.

> **Status: early work in progress (0.1.0-dev).** The client side works end to end: it drives `claude-agent-acp` 0.112.0 through initialize, session creation and streamed updates. The API may still change.

## Why

ACP has official SDKs in Rust, TypeScript, Python, Kotlin and Java, and community ones in many languages, but none in PHP. This package aims to let a PHP application drive agents the same way an editor does: open sessions, send prompts, stream updates, answer permission and elicitation requests.

## Driving an agent

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

### Turns

- `$session->prompt()` returns at once. Read `$turn->updates()` as they stream, or just wait with `stopReason()`, `text()` or `wait()`: updates are buffered, reading them is optional.
- `$turn->cancel()` (or a cancellation passed to `prompt()`) cancels the ACP way: the agent ends the turn with the `cancelled` stop reason, and pending permission requests are answered with the `cancelled` outcome right away, even if your handler is still waiting.
- One turn at a time per session, as in ACP v1 (`TurnInProgress` otherwise).
- `$session->onUpdate()` sees every update, inside turns or not, to log or persist a session.
- `$client->loadSession($id, cwd: …)` resumes a session; the replayed conversation is in `$session->history()`.

### Permissions

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

### Errors

- `RpcError`: the agent answered with an error. `$e->isAuthRequired()` for `-32000`, then `$client->authenticate($methodId)`.
- `ConnectionClosed`: the agent exited. `$agent->stderr()` keeps its last lines.
- `InvalidPayload`: a message does not match the schema.

## Testing your client

`FakeAgent` is a scriptable agent, in memory or as a real subprocess:

```php
use Acp\Schema\{StopReason, ToolKind};
use Acp\Testing\{FakeAgent, FakeTurn};

$client = FakeAgent::create()
    ->onPrompt(function (FakeTurn $turn) {
        $turn->toolCall('call_1', 'Run the tests', ToolKind::Execute);
        if ($turn->requestPermission('call_1', 'npm test') === null) {
            return StopReason::Cancelled;
        }
        $turn->say('All green.');
    })
    ->connect(permissions: $myHandler);
```

`$turn->readFile()`, `writeFile()`, `plan()`, `think()`, `untilCancelled()` and `FakeAgent::received()` cover the rest. `FakeAgent::runOverStdio()` turns a small PHP script into an agent process.

## The schema layer

Every type of the protocol is a generated, immutable PHP class built from the official `schema.json` (currently `schema-v1.35.0`, see `resources/schema/v1/VERSION`).

```php
use Acp\Schema;
use Acp\Schema\ContentBlock\TextContent;
use Acp\Schema\SessionUpdate;

// Build messages by hand…
$prompt = new Schema\PromptRequest(
    sessionId: 'sess_abc123',
    prompt: [new TextContent('Switch the game to a dark theme')],
);
json_encode($prompt); // {"sessionId":"sess_abc123","prompt":[{"type":"text","text":"…"}]}

// …or hydrate what an agent sends.
$update = Schema\SessionNotification::fromArray($params)->update;

if ($update instanceof SessionUpdate\AgentMessageChunk && $update->content instanceof TextContent) {
    echo $update->content->text;
}

if ($update instanceof SessionUpdate\ToolCall) {
    echo $update->title;

    if ($update->status === Schema\ToolCallStatus::Failed) {
        // …
    }
}

if ($update instanceof SessionUpdate\Unrecognized) {
    // Sent by a newer agent: $update->tag(), $update->data
}
```

### Mapping rules

| Schema | PHP |
| --- | --- |
| Object | `final readonly class` with `fromArray()` and `jsonSerialize()` |
| Tagged union (`sessionUpdate`, `type`, `outcome`…) | `abstract readonly class` dispatching to one `final` class per variant |
| String or integer constants | Backed enum |
| Scalar aliases and unions (`SessionId`, `RequestId`…) | Native PHP types |
| `_meta` | `$meta`, kept as an array on every type |
| Methods (`meta.json`) | `AgentMethod`, `ClientMethod`, `ProtocolMethod` enums, with `paramsClass()` and `resultClass()` |

### Forward compatibility

A client built with this SDK must keep working when an agent speaks a newer version of the protocol:

- **Open enums.** An unknown value (`"stopReason": "budget_exceeded"`) is kept as a raw string instead of throwing. Typed properties are `StopReason|string`.
- **Unrecognized variants.** An unknown union variant becomes `…\Unrecognized`, which keeps the raw payload and serializes it back untouched.
- **Lenient fields.** Fields flagged `x-deserialize-default-on-error` in the schema fall back to their default when invalid, and lists flagged `x-deserialize-skip-invalid-items` drop invalid items, like the official Rust SDK does.
- **Schema defaults** are applied on hydration (`$capabilities->loadSession` is `false`, not `null`) and not re-emitted on serialization.

Invalid payloads throw `Acp\Exception\InvalidPayload` with the full path of the faulty field (`prompt[1].text: expected string, got int`).

## Roadmap

- [x] Schema generator and generated types (v1)
- [x] JSON-RPC core: bidirectional requests, notifications, errors, `$/cancel_request`
- [x] Transports: stdio subprocess (amphp v3), in-memory for tests
- [x] Client API: `initialize`, `authenticate`, sessions, prompt turns, cancellation, `session/load`, modes
- [x] Client handlers: permissions, file system
- [x] `FakeAgent`: a scriptable agent to test ACP clients without a real one
- [ ] Client handlers: elicitation, terminals
- [ ] `session/list`, `session/resume`, `session/close`, config options
- [ ] Integration tests against `claude-agent-acp` with real credentials
- [ ] Agent side, to write ACP agents in PHP
- [ ] Protocol v2, behind version negotiation

## Development

```bash
composer install
composer test           # PHPUnit
composer analyse        # PHPStan
composer check-schema   # fails if src/Schema is out of date
```

`src/Schema` is generated: never edit it by hand. To follow a new release of the protocol:

```bash
composer update-schema               # latest release, or pass a tag: composer update-schema -- schema-v1.36.0
composer generate
git diff src/Schema                  # review what changed in the protocol
```

## License

MIT
