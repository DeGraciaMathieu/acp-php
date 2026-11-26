<?php

declare(strict_types=1);

namespace Acp\Testing;

use Acp\Rpc\Connection;
use Acp\Schema\ClientMethod;
use Acp\Schema\ContentBlock\TextContent;
use Acp\Schema\PermissionOption;
use Acp\Schema\PermissionOptionKind;
use Acp\Schema\PlanEntry;
use Acp\Schema\PlanEntryPriority;
use Acp\Schema\PlanEntryStatus;
use Acp\Schema\PromptRequest;
use Acp\Schema\ReadTextFileRequest;
use Acp\Schema\ReadTextFileResponse;
use Acp\Schema\RequestPermissionOutcome\Selected;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\RequestPermissionResponse;
use Acp\Schema\SessionNotification;
use Acp\Schema\SessionUpdate;
use Acp\Schema\ToolCallContent\Content;
use Acp\Schema\ToolCallStatus;
use Acp\Schema\ToolCallUpdate;
use Acp\Schema\ToolKind;
use Acp\Schema\WriteTextFileRequest;
use Acp\Support\Cast;
use Amp\DeferredFuture;

/**
 * What a FakeAgent's prompt handler uses to play its part of a turn.
 */
final class FakeTurn
{
    private bool $cancelled = false;

    /** @var DeferredFuture<null> */
    private DeferredFuture $cancellation;

    /**
     * @internal created by FakeAgent
     */
    public function __construct(
        public readonly PromptRequest $request,
        private readonly Connection $rpc,
        private readonly FakeAgent $agent,
    ) {
        $this->cancellation = new DeferredFuture();
    }

    public function sessionId(): string
    {
        return $this->request->sessionId;
    }

    /**
     * Text of the prompt.
     */
    public function prompt(): string
    {
        $text = '';
        foreach ($this->request->prompt as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return $text;
    }

    public function say(string $text): void
    {
        $this->send(new SessionUpdate\AgentMessageChunk(new TextContent($text)));
    }

    public function think(string $text): void
    {
        $this->send(new SessionUpdate\AgentThoughtChunk(new TextContent($text)));
    }

    public function toolCall(string $id, string $title, ToolKind $kind = ToolKind::Other, ToolCallStatus $status = ToolCallStatus::Pending): void
    {
        $this->send(new SessionUpdate\ToolCall(toolCallId: $id, title: $title, kind: $kind, status: $status));
    }

    public function updateToolCall(string $id, ToolCallStatus $status, ?string $output = null): void
    {
        $this->send(new SessionUpdate\ToolCallUpdate(
            toolCallId: $id,
            status: $status,
            content: $output === null ? null : [new Content(new TextContent($output))],
        ));
    }

    public function plan(string ...$entries): void
    {
        $this->send(new SessionUpdate\Plan(array_map(
            static fn (string $entry): PlanEntry => new PlanEntry($entry, PlanEntryPriority::Medium, PlanEntryStatus::Pending),
            array_values($entries),
        )));
    }

    public function send(SessionUpdate $update): void
    {
        $this->agent->record($this->sessionId(), $update);
        $this->rpc->notify(ClientMethod::SessionUpdate->value, new SessionNotification($this->sessionId(), $update));
    }

    /**
     * Asks the client for permission, offering "allow" (once), "always" and "reject".
     *
     * @return string|null The selected option id, or null when the turn was cancelled.
     */
    public function requestPermission(string $toolCallId, string $title, ToolKind $kind = ToolKind::Execute): ?string
    {
        $response = RequestPermissionResponse::fromArray(Cast::obj($this->rpc->request(
            ClientMethod::SessionRequestPermission->value,
            new RequestPermissionRequest(
                sessionId: $this->sessionId(),
                toolCall: new ToolCallUpdate(toolCallId: $toolCallId, kind: $kind, title: $title),
                options: [
                    new PermissionOption('allow', 'Allow', PermissionOptionKind::AllowOnce),
                    new PermissionOption('always', 'Always allow', PermissionOptionKind::AllowAlways),
                    new PermissionOption('reject', 'Reject', PermissionOptionKind::RejectOnce),
                ],
            ),
        ), 'result'));

        return $response->outcome instanceof Selected ? $response->outcome->optionId : null;
    }

    public function readFile(string $path, ?int $line = null, ?int $limit = null): string
    {
        return ReadTextFileResponse::fromArray(Cast::obj($this->rpc->request(
            ClientMethod::FsReadTextFile->value,
            new ReadTextFileRequest(sessionId: $this->sessionId(), path: $path, line: $line, limit: $limit),
        ), 'result'))->content;
    }

    public function writeFile(string $path, string $content): void
    {
        $this->rpc->request(
            ClientMethod::FsWriteTextFile->value,
            new WriteTextFileRequest(sessionId: $this->sessionId(), path: $path, content: $content),
        );
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Blocks until the client cancels the turn: lets a test simulate a long-running agent.
     */
    public function untilCancelled(): void
    {
        $this->cancellation->getFuture()->await();
    }

    /**
     * @internal
     */
    public function cancel(): void
    {
        $this->cancelled = true;
        if (! $this->cancellation->isComplete()) {
            $this->cancellation->complete();
        }
    }
}
