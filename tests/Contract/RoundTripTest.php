<?php

declare(strict_types=1);

namespace Acp\Tests\Contract;

use Acp\Schema;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Real-world messages must survive hydration and serialization unchanged.
 */
final class RoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{0: class-string, 1: string}>
     */
    public static function messages(): iterable
    {
        yield 'initialize request' => [Schema\InitializeRequest::class, <<<'JSON'
            {"protocolVersion": 1, "clientCapabilities": {"fs": {"readTextFile": true, "writeTextFile": true}, "terminal": true}, "clientInfo": {"name": "etabli", "version": "0.1.0"}}
            JSON];

        yield 'initialize response' => [Schema\InitializeResponse::class, <<<'JSON'
            {"protocolVersion": 1, "agentCapabilities": {"loadSession": true, "promptCapabilities": {"image": true, "embeddedContext": true}, "mcpCapabilities": {"http": true}}, "authMethods": [{"id": "claude-login", "name": "Log in with Claude"}], "agentInfo": {"name": "claude-code-acp", "version": "0.9.0"}}
            JSON];

        yield 'session/new request with an MCP server' => [Schema\NewSessionRequest::class, <<<'JSON'
            {"cwd": "/home/ubuntu/worktrees/card-42", "mcpServers": [{"name": "etabli", "command": "/usr/bin/php", "args": ["artisan", "mcp:start"], "env": [{"name": "APP_ENV", "value": "production"}]}, {"type": "http", "name": "docs", "url": "https://example.test/mcp", "headers": []}]}
            JSON];

        yield 'session/new response' => [Schema\NewSessionResponse::class, <<<'JSON'
            {"sessionId": "sess_abc123"}
            JSON];

        yield 'session/prompt request' => [Schema\PromptRequest::class, <<<'JSON'
            {"sessionId": "sess_abc123", "prompt": [{"type": "text", "text": "Passe le thème en sombre"}, {"type": "resource_link", "uri": "file:///home/ubuntu/game/index.html", "name": "index.html"}, {"type": "resource", "resource": {"uri": "file:///notes.md", "text": "# Notes", "mimeType": "text/markdown"}}]}
            JSON];

        yield 'session/prompt response' => [Schema\PromptResponse::class, <<<'JSON'
            {"stopReason": "end_turn"}
            JSON];

        yield 'agent message chunk' => [Schema\SessionNotification::class, <<<'JSON'
            {"sessionId": "sess_abc123", "update": {"sessionUpdate": "agent_message_chunk", "content": {"type": "text", "text": "Je regarde le CSS."}}}
            JSON];

        yield 'tool call' => [Schema\SessionNotification::class, <<<'JSON'
            {"sessionId": "sess_abc123", "update": {"sessionUpdate": "tool_call", "toolCallId": "call_1", "title": "Lecture de style.css", "kind": "read", "status": "pending", "locations": [{"path": "/game/style.css", "line": 12}], "rawInput": {"path": "/game/style.css"}}}
            JSON];

        yield 'tool call update with a diff' => [Schema\SessionNotification::class, <<<'JSON'
            {"sessionId": "sess_abc123", "update": {"sessionUpdate": "tool_call_update", "toolCallId": "call_1", "status": "completed", "content": [{"type": "diff", "path": "/game/style.css", "oldText": "background: #fff;", "newText": "background: #111;"}, {"type": "content", "content": {"type": "text", "text": "1 fichier modifié"}}]}}
            JSON];

        yield 'plan' => [Schema\SessionNotification::class, <<<'JSON'
            {"sessionId": "sess_abc123", "update": {"sessionUpdate": "plan", "entries": [{"content": "Changer les couleurs", "priority": "high", "status": "in_progress"}, {"content": "Vérifier le contraste", "priority": "medium", "status": "pending"}]}}
            JSON];

        yield 'available commands' => [Schema\SessionNotification::class, <<<'JSON'
            {"sessionId": "sess_abc123", "update": {"sessionUpdate": "available_commands_update", "availableCommands": [{"name": "review", "description": "Relit les changements", "input": {"hint": "fichier à relire"}}]}}
            JSON];

        yield 'permission request' => [Schema\RequestPermissionRequest::class, <<<'JSON'
            {"sessionId": "sess_abc123", "toolCall": {"toolCallId": "call_2", "title": "npm test", "kind": "execute"}, "options": [{"optionId": "allow", "name": "Autoriser", "kind": "allow_once"}, {"optionId": "always", "name": "Toujours", "kind": "allow_always"}, {"optionId": "reject", "name": "Refuser", "kind": "reject_once"}]}
            JSON];

        yield 'permission granted' => [Schema\RequestPermissionResponse::class, <<<'JSON'
            {"outcome": {"outcome": "selected", "optionId": "allow"}}
            JSON];

        yield 'permission cancelled' => [Schema\RequestPermissionResponse::class, <<<'JSON'
            {"outcome": {"outcome": "cancelled"}}
            JSON];

        yield 'fs/read_text_file' => [Schema\ReadTextFileRequest::class, <<<'JSON'
            {"sessionId": "sess_abc123", "path": "/game/index.html", "line": 10, "limit": 50}
            JSON];

        yield 'fs/write_text_file' => [Schema\WriteTextFileRequest::class, <<<'JSON'
            {"sessionId": "sess_abc123", "path": "/game/style.css", "content": "body { background: #111; }\n"}
            JSON];

        yield 'session/cancel' => [Schema\CancelNotification::class, <<<'JSON'
            {"sessionId": "sess_abc123"}
            JSON];

        yield 'error with an ACP code' => [Schema\Error::class, <<<'JSON'
            {"code": -32000, "message": "Authentication required"}
            JSON];

        yield 'metadata is preserved' => [Schema\PromptResponse::class, <<<'JSON'
            {"stopReason": "end_turn", "_meta": {"claudecode": {"cost": 0.12}}}
            JSON];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('messages')]
    public function a_message_survives_a_round_trip(string $class, string $json): void
    {
        $object = $class::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));

        self::assertInstanceOf(JsonSerializable::class, $object);
        self::assertEquals(
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) json_encode($object, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
