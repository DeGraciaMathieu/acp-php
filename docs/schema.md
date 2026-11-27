# The schema layer

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

## Mapping rules

| Schema | PHP |
| --- | --- |
| Object | `final readonly class` with `fromArray()` and `jsonSerialize()` |
| Tagged union (`sessionUpdate`, `type`, `outcome`…) | `abstract readonly class` dispatching to one `final` class per variant |
| String or integer constants | Backed enum |
| Scalar aliases and unions (`SessionId`, `RequestId`…) | Native PHP types |
| `_meta` | `$meta`, kept as an array on every type |
| Methods (`meta.json`) | `AgentMethod`, `ClientMethod`, `ProtocolMethod` enums, with `paramsClass()` and `resultClass()` |

## Forward compatibility

A client built with this SDK must keep working when an agent speaks a newer version of the protocol:

- **Open enums.** An unknown value (`"stopReason": "budget_exceeded"`) is kept as a raw string instead of throwing. Typed properties are `StopReason|string`.
- **Unrecognized variants.** An unknown union variant becomes `…\Unrecognized`, which keeps the raw payload and serializes it back untouched.
- **Lenient fields.** Fields flagged `x-deserialize-default-on-error` in the schema fall back to their default when invalid, and lists flagged `x-deserialize-skip-invalid-items` drop invalid items, like the official Rust SDK does.
- **Schema defaults** are applied on hydration (`$capabilities->loadSession` is `false`, not `null`) and not re-emitted on serialization.

Invalid payloads throw `Acp\Exception\InvalidPayload` with the full path of the faulty field (`prompt[1].text: expected string, got int`).
