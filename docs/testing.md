# Testing your client

`FakeAgent` plays the agent in your tests: no API key, no network, the same answers on every run. You script what the agent does during a turn, and your code talks to it like it would to Claude.

## A first turn

`onPrompt()` receives a `FakeTurn`, the agent's side of the turn. Here the agent just answers:

```php
use Acp\Testing\{FakeAgent, FakeTurn};

$client = FakeAgent::create()
    ->onPrompt(fn (FakeTurn $turn) => $turn->say('All green.'))
    ->connect();
$client->initialize();

$turn = $client->newSession(cwd: '/tmp')->prompt('Run the tests');

$turn->text(); // "All green."
```

`connect()` links the client to the fake agent in memory. There is no agent process, so give the session a `cwd`.

## Testing your permissions

Say your code refuses commands and allows everything else:

```php
use Acp\Client\Permission\{CallbackPermissions, Outcome};
use Acp\Schema\{RequestPermissionRequest, ToolKind};

$permissions = new CallbackPermissions(fn (RequestPermissionRequest $request) => match ($request->toolCall->kind) {
    ToolKind::Execute => Outcome::reject($request),
    default => Outcome::approve($request),
});
```

Pass it to `connect()`, and make the fake agent ask for a permission. `requestPermission()` returns the option your handler picked: `allow`, `always` or `reject`.

```php
$client = FakeAgent::create()
    ->onPrompt(function (FakeTurn $turn) {
        $answer = $turn->requestPermission('call_1', 'npm test', ToolKind::Execute);
        $turn->say($answer === 'reject' ? 'I was not allowed to run the tests.' : 'All green.');
    })
    ->connect(permissions: $permissions);
$client->initialize();

$client->newSession(cwd: '/tmp')->prompt('Run the tests')->text(); // "I was not allowed to run the tests."
```

`requestPermission()` returns `null` when the client cancelled the turn meanwhile. Return `StopReason::Cancelled` from `onPrompt()` to end the turn the way a real agent would.

## Checking what the client sent

Keep the `FakeAgent` in a variable: `received()` lists the messages it got from the client, optionally for one method.

```php
$agent = FakeAgent::create()->onPrompt(fn (FakeTurn $turn) => $turn->say('Done.'));
$client = $agent->connect();
$client->initialize();
$client->newSession(cwd: '/tmp')->prompt('Run the tests')->wait();

$agent->received('session/prompt')[0]['prompt'][0]['text']; // "Run the tests"
```

## The rest of the turn

`FakeTurn` plays everything else an agent does:

- `toolCall()` and `updateToolCall()` report a tool call and its result;
- `plan()` and `think()` stream a plan and thoughts;
- `readFile()` and `writeFile()` go through your file system handler;
- `untilCancelled()` waits for the client to cancel, like a long task.

## A real agent process

`FakeAgent::runOverStdio()` turns a small PHP script into an agent process, to test your code through `AgentProcess::start()`:

```php
// fake-agent.php
use Acp\Testing\{FakeAgent, FakeTurn};

require __DIR__ . '/vendor/autoload.php';

FakeAgent::create()
    ->onPrompt(fn (FakeTurn $turn) => $turn->say('All green.'))
    ->runOverStdio();
```

```php
$agent = AgentProcess::start('php', ['fake-agent.php']);
```
