<?php

declare(strict_types=1);

namespace Acp\Tests\Contract;

use Acp\Exception\InvalidPayload;
use Acp\Schema;
use Acp\Schema\ContentBlock\TextContent;
use Acp\Schema\SessionUpdate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HydrationTest extends TestCase
{
    #[Test]
    public function tagged_unions_resolve_to_their_variant(): void
    {
        $notification = Schema\SessionNotification::fromArray([
            'sessionId' => 'sess_1',
            'update' => ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => 'Salut']],
        ]);

        self::assertInstanceOf(SessionUpdate\AgentMessageChunk::class, $notification->update);
        self::assertInstanceOf(TextContent::class, $notification->update->content);
        self::assertSame('Salut', $notification->update->content->text);
    }

    #[Test]
    public function known_enum_values_become_cases(): void
    {
        $response = Schema\PromptResponse::fromArray(['stopReason' => 'cancelled']);

        self::assertSame(Schema\StopReason::Cancelled, $response->stopReason);
    }

    #[Test]
    public function untagged_unions_resolve_by_shape(): void
    {
        $text = Schema\EmbeddedResourceResource::fromArray(['uri' => 'file:///a.md', 'text' => '# A']);
        $blob = Schema\EmbeddedResourceResource::fromArray(['uri' => 'file:///a.png', 'blob' => 'iVBORw0K']);

        self::assertInstanceOf(Schema\EmbeddedResourceResource\TextResourceContents::class, $text);
        self::assertInstanceOf(Schema\EmbeddedResourceResource\BlobResourceContents::class, $blob);
    }

    #[Test]
    public function a_missing_tag_falls_back_to_the_default_variant(): void
    {
        $server = Schema\McpServer::fromArray(['name' => 'etabli', 'command' => 'php', 'args' => [], 'env' => []]);

        self::assertInstanceOf(Schema\McpServer\Stdio::class, $server);
        self::assertArrayNotHasKey('type', (array) $server->jsonSerialize());
    }

    #[Test]
    public function a_missing_required_field_reports_its_path(): void
    {
        try {
            Schema\SessionNotification::fromArray([
                'sessionId' => 'sess_1',
                'update' => ['sessionUpdate' => 'agent_message_chunk'],
            ]);
            self::fail('An exception was expected.');
        } catch (InvalidPayload $e) {
            self::assertSame('update.content', $e->path());
        }
    }

    #[Test]
    public function a_wrong_type_inside_a_list_reports_its_index(): void
    {
        try {
            Schema\PromptRequest::fromArray([
                'sessionId' => 'sess_1',
                'prompt' => [['type' => 'text', 'text' => 'ok'], ['type' => 'text', 'text' => 42]],
            ]);
            self::fail('An exception was expected.');
        } catch (InvalidPayload $e) {
            self::assertSame('prompt[1].text', $e->path());
            self::assertStringContainsString('expected string, got int', $e->getMessage());
        }
    }

    #[Test]
    public function lenient_fields_fall_back_to_their_default_on_invalid_values(): void
    {
        $chunk = SessionUpdate\AgentMessageChunk::fromArray([
            'content' => ['type' => 'text', 'text' => 'Salut'],
            'messageId' => ['not', 'a', 'string'],
        ]);

        self::assertNull($chunk->messageId);
    }

    #[Test]
    public function invalid_list_items_are_skipped_when_the_schema_allows_it(): void
    {
        $update = SessionUpdate\ToolCallUpdate::fromArray([
            'toolCallId' => 'call_1',
            'content' => [
                ['type' => 'content', 'content' => ['type' => 'text', 'text' => 'ok']],
                ['type' => 'diff'],
            ],
        ]);

        self::assertCount(1, $update->content ?? []);
    }

    #[Test]
    public function schema_defaults_are_applied_when_fields_are_missing(): void
    {
        $response = Schema\InitializeResponse::fromArray(['protocolVersion' => 1]);

        self::assertNotNull($response->agentCapabilities);
        self::assertFalse($response->agentCapabilities->loadSession);
        self::assertNotNull($response->agentCapabilities->promptCapabilities);
        self::assertFalse($response->agentCapabilities->promptCapabilities->image);
        self::assertSame([], $response->authMethods);
    }

    #[Test]
    public function empty_objects_stay_objects(): void
    {
        self::assertSame('{}', json_encode(Schema\AuthenticateResponse::fromArray([])));
        self::assertSame('{"stopReason":"end_turn","_meta":{}}', json_encode(new Schema\PromptResponse(Schema\StopReason::EndTurn, meta: [])));
    }

    #[Test]
    public function objects_can_be_built_by_hand(): void
    {
        $request = new Schema\PromptRequest(
            sessionId: 'sess_1',
            prompt: [new TextContent('Passe le thème en sombre')],
        );

        self::assertSame(
            '{"sessionId":"sess_1","prompt":[{"type":"text","text":"Passe le thème en sombre"}]}',
            json_encode($request, JSON_UNESCAPED_UNICODE),
        );
    }

    #[Test]
    public function methods_know_their_params_and_result_classes(): void
    {
        self::assertSame(Schema\PromptRequest::class, Schema\AgentMethod::SessionPrompt->paramsClass());
        self::assertSame(Schema\PromptResponse::class, Schema\AgentMethod::SessionPrompt->resultClass());
        self::assertTrue(Schema\AgentMethod::SessionCancel->isNotification());
        self::assertTrue(Schema\ClientMethod::SessionUpdate->isNotification());
        self::assertSame(Schema\RequestPermissionRequest::class, Schema\ClientMethod::from('session/request_permission')->paramsClass());
    }
}
