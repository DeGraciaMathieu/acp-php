# Testing your client

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
