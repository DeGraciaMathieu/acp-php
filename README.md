# acp-php

**Drive Claude Code from PHP, like an editor does.**

Running Claude Code headless from PHP usually looks like this:

```php
$process = proc_open(
    ['claude', '-p', $prompt, '--output-format', 'stream-json', '--verbose', '--dangerously-skip-permissions'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);

while (($line = fgets($pipes[1])) !== false) {
    $event = json_decode($line, true);
    // Claude's own event format: guess the shapes, follow their changes.
}
```

A process per prompt, unless you stream its input as JSON too. A stream of untyped JSON to decode. And permissions decided before the agent even starts: skip them all, or list the allowed tools upfront. Answering a permission when the agent asks for it means building an MCP permission tool.

acp-php talks to Claude Code through the [Agent Client Protocol](https://agentclientprotocol.com), the protocol editors such as Zed use to drive coding agents:

```php
use Acp\AgentProcess;
use Acp\Client\Permission\{CallbackPermissions, Outcome};
use Acp\Schema\{RequestPermissionRequest, SessionUpdate, ToolKind};

$agent = AgentProcess::start('claude-agent-acp', cwd: '/path/to/project');

$client = $agent->connect(permissions: new CallbackPermissions(
    fn (RequestPermissionRequest $request) => $request->toolCall->kind === ToolKind::Execute
        ? Outcome::reject($request)
        : Outcome::approve($request),
));
$client->initialize();

$turn = $client->newSession(cwd: '/path/to/project')->prompt('Fix the failing test');

foreach ($turn->updates() as $update) {
    if ($update instanceof SessionUpdate\ToolCall) {
        echo "→ {$update->title}\n";
    }
}

echo $turn->text();
$agent->stop();
```

## What you get

- **Permissions decided live.** Claude asks before it edits or runs something, your code answers: a policy, a human on Slack, a CI rule.
- **A typed stream.** Tool calls, plans and messages arrive as PHP objects, not JSON to guess.
- **Sessions that last.** Several prompts in one conversation, cancel a turn, resume a session later.
- **Files under control.** Serve Claude's reads and writes yourself, confined to a directory.
- **Testable.** `FakeAgent` plays the agent in your tests: no API key, no network.

## Install

```bash
composer require degraciamathieu/acp-php
npm i -g @agentclientprotocol/claude-agent-acp
```

`claude-agent-acp` is the ACP adapter of Claude Code. It uses your Claude Code login: start the agent with `env: ['ANTHROPIC_API_KEY' => null]` to keep it off API billing.

## Test without an agent

```php
use Acp\Testing\{FakeAgent, FakeTurn};

$client = FakeAgent::create()
    ->onPrompt(fn (FakeTurn $turn) => $turn->say('All green.'))
    ->connect();
```

## Learn more

- [Driving an agent](docs/client.md): turns, permissions, files, sessions, errors, debugging
- [Testing your client](docs/testing.md)
- [The schema layer](docs/schema.md)
- [Examples](examples/)
- [Contributing](docs/contributing.md): roadmap and development

> **Early work in progress (0.1.0-dev).** Validated against `claude-agent-acp` 0.112.0. The API may still change.

## License

MIT
