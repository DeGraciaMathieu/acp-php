# acp-php

PHP client SDK for the [Agent Client Protocol](https://agentclientprotocol.com) (ACP): JSON-RPC 2.0 over stdio, to drive coding agents such as Claude Code (`claude-agent-acp`) from PHP. PHP 8.2 to 8.6, amphp v3.

## Layout

- `src/Schema`: the protocol types, **generated** from `resources/schema/v1/schema.json` by `bin/generate-schema.php`. Never edit them by hand: change the generator, then `composer generate`.
- `src/Rpc`: JSON-RPC connection, transports (stdio, in-memory), `Dumper`.
- `src/Client`: the public API (`ClientConnection`, `Session`, `Turn`) and the permission and file system handlers.
- `src/Testing`: `FakeAgent` and `FakeTurn`. Public API too: users test their clients with them.
- `src/AgentProcess.php`: runs an agent as a subprocess.

## Protocol changes must not break users

ACP evolves, and an agent may speak a newer schema than the SDK. A field, value or variant the SDK does not know must never make a user's code fail. The generator guarantees it, keep it that way:

- **Open enums.** An unknown enum value is kept as a raw string: fields are typed `ToolKind|string`. A `match` on them needs a `default` arm.
- **`Unrecognized` variants.** Every tagged union (session updates, content blocks, auth methods…) has one: a new variant from the agent arrives as `Unrecognized`, it does not throw.
- **Unknown fields are ignored.** Fields the schema marks `x-deserialize-default-on-error` fall back to their default when invalid, lists marked `x-deserialize-skip-invalid-items` drop the invalid items.
- **Unknown messages.** An unknown request from the agent gets a JSON-RPC "method not found" error. An unknown notification is ignored.
- The one hard failure: a different protocol version at `initialize()` (`UnsupportedProtocolVersion`).

On the public API, add rather than change: new parameters are optional and named, nothing is removed or reordered.

To follow a new schema release: `composer update-schema`, `composer generate`, then review `git diff src/Schema`: it is the protocol changelog.

## Async

The API reads synchronously but runs on amphp fibers. Handlers must not block (`sleep()`, blocking I/O): use amphp equivalents.

- `Session::prompt()` returns at once. Wait for the turn (`wait()`, `text()`) before checking what the agent received.
- `TimeoutCancellation` does not keep the event loop alive: with an in-memory `FakeAgent`, a turn waiting on it deadlocks. Use `DeferredCancellation` in tests.

## Tests and checks

```bash
composer test           # both suites
composer test:feature   # use cases, through the public API, with FakeAgent
composer test:contract  # generated src/Schema against the JSON schema
composer analyse        # PHPStan level 8, on src and bin
composer check-schema   # fails if src/Schema is out of date
```

CI runs all of them on PHP 8.2 to 8.6.

Tests are functional: each one checks a behaviour a user relies on, through the public API, as a use case ("a refused command is reported to the agent"). No tests of implementation details: private methods, internal classes, how a result is computed. A refactoring that keeps the behaviour must not break a test.

## Claude Code, as seen through ACP

Observed with `claude-agent-acp` 0.112.0:

- It uses the Claude Code login only when `ANTHROPIC_API_KEY` is unset: `env: ['ANTHROPIC_API_KEY' => null]`.
- It may run in a mode that never asks for permissions: `$session->setMode('default')` to receive them.
- A `tool_call` first carries a generic title ("Read File", "Terminal"), the detailed one comes in a later `tool_call_update`.
- It never sends `ToolKind::Delete` or `Move`: deleting and moving go through Bash, as `Execute`.
- It loads the user's `~/.claude/CLAUDE.md`, which may change its language or behaviour.

## Documentation

`README.md`, `docs/client.md`, `docs/testing.md`, `docs/schema.md`, `examples/`. Docs are written step by step: each section adds one concept, and every variable is defined before it is used. Run each example against the SDK before committing it.
