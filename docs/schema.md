# The schema layer

Every message of the protocol has a PHP class in `Acp\Schema`, generated from the official ACP schema. You mostly read them: what the agent streams during a turn, its permission requests, a replayed history. You build them more rarely, to send more than text in a prompt.

They are immutable: their properties are public and `readonly`.

## Reading a turn

`$turn->updates()` yields `SessionUpdate` objects, one class per kind of update. Test them with `instanceof`:

```php
use Acp\Schema\ContentBlock\TextContent;
use Acp\Schema\SessionUpdate;
use Acp\Schema\ToolCallStatus;

foreach ($turn->updates() as $update) {
    if ($update instanceof SessionUpdate\AgentMessageChunk && $update->content instanceof TextContent) {
        echo $update->content->text;
    }

    if ($update instanceof SessionUpdate\ToolCallUpdate && $update->status === ToolCallStatus::Failed) {
        echo "✗ {$update->toolCallId}\n";
    }
}
```

The updates a turn brings:

| Class | What it holds |
| --- | --- |
| `AgentMessageChunk` | a piece of the answer, in `$content` |
| `AgentThoughtChunk` | a piece of the agent's reasoning, in `$content` |
| `UserMessageChunk` | a piece of the user's message, in a replayed history |
| `ToolCall` | a tool call starts: `$toolCallId`, `$title`, `$kind`, `$status` |
| `ToolCallUpdate` | the same tool call moves on: new `$status`, `$title`, `$content` |
| `Plan` | the agent's plan, in `$entries`: each has a `$content`, a `$priority`, a `$status` |
| `UsageUpdate` | context window used, in tokens (`$used` of `$size`), and `$cost` |
| `CurrentModeUpdate` | the session switched to `$currentModeId` |
| `AvailableCommandsUpdate` | the slash commands the agent offers |
| `SessionInfoUpdate` | the session got a `$title` |

The schema has a few more (`SubagentUpdate`, `ConfigOptionUpdate`…), and some the agent sends only when the client asks for them.

`$content` is a `ContentBlock`: `TextContent` most of the time, `ImageContent`, `AudioContent`, `ResourceLink` or `EmbeddedResource` otherwise.

## Sending more than text

`prompt()` takes a string, or a list of content blocks: to show an image, or point the agent to a file.

```php
use Acp\Schema\ContentBlock\{ImageContent, ResourceLink, TextContent};

$session->prompt([
    new TextContent('Make the home page look like this mockup'),
    new ImageContent(base64_encode(file_get_contents('mockup.png')), 'image/png'),
    new ResourceLink(name: 'home.html', uri: 'file:///home/me/game/home.html'),
]);
```

Every agent accepts text and resource links. Images, audio and embedded resources depend on the agent: check its capabilities first.

```php
$client->agentCapabilities()->promptCapabilities?->image; // true for Claude Code
```

## When the agent is newer than the SDK

ACP evolves, and an agent may speak a newer version of the schema than this SDK knows. Nothing then throws:

- **An unknown value** stays a raw string. Properties that hold a value from a list are typed for it: `ToolKind|string`, `ToolCallStatus|string`.
- **An unknown kind of update or content** arrives as `Unrecognized`, with the raw payload in `$data` and its type in `tag()`.

Write your code for it: give each `match` a `default` arm, and decide what to do with what you don't know, ignore it or log it:

```php
// $update is a SessionUpdate\ToolCall
$label = match ($update->kind) {
    ToolKind::Read => 'read',
    ToolKind::Edit => 'edit',
    ToolKind::Execute => 'run',
    default => 'other', // includes kinds added after this SDK
};

if ($update instanceof SessionUpdate\Unrecognized) {
    $logger->info('Unknown update from the agent', ['type' => $update->tag(), 'data' => $update->data]);
}
```

## Invalid messages

A message that breaks the schema, a missing required field or a wrong type, throws `Acp\Exception\InvalidPayload`. Its message gives the path of the faulty field: `Invalid ACP payload at "prompt[1].text": expected string, got int`.

## Reference

The classes are generated from `resources/schema/v1/schema.json`, currently `schema-v1.35.0` (see `resources/schema/v1/VERSION`). Building them by hand is rarely needed: every class has `fromArray()` and serializes back with `json_encode()`.

| Schema | PHP |
| --- | --- |
| Object | `final readonly class` with `fromArray()` and `jsonSerialize()` |
| Tagged union (`sessionUpdate`, `type`, `outcome`…) | `abstract readonly class` dispatching to one `final` class per variant, plus `Unrecognized` |
| String or integer constants | Backed enum, open: unknown values stay raw |
| Scalar aliases and unions (`SessionId`, `RequestId`…) | Native PHP types |
| `_meta` | `$meta`, kept as an array on every type |
| Methods (`meta.json`) | `AgentMethod`, `ClientMethod`, `ProtocolMethod` enums, with `paramsClass()` and `resultClass()` |

Hydration is lenient where the schema allows it:

- Fields flagged `x-deserialize-default-on-error` fall back to their default when invalid, and lists flagged `x-deserialize-skip-invalid-items` drop invalid items, like the official Rust SDK does.
- Schema defaults are applied on hydration (`$capabilities->loadSession` is `false`, not `null`) and not re-emitted on serialization.
- `Unrecognized` serializes its payload back untouched.
