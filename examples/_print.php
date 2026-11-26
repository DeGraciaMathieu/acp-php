<?php

// Shared by the examples: prints a session update in the terminal.

declare(strict_types=1);

use Acp\Schema\ContentBlock\TextContent;
use Acp\Schema\SessionUpdate;

function printUpdate(SessionUpdate $update): void
{
    match (true) {
        $update instanceof SessionUpdate\AgentMessageChunk => print($update->content instanceof TextContent ? $update->content->text : ''),
        $update instanceof SessionUpdate\AgentThoughtChunk => print(''),
        $update instanceof SessionUpdate\ToolCall => print("\n  ⚙ {$update->title}\n"),
        $update instanceof SessionUpdate\ToolCallUpdate => $update->status !== null
            ? print('  ' . (is_string($update->status) ? $update->status : $update->status->value) . "\n")
            : null,
        $update instanceof SessionUpdate\Plan => print("\n  Plan: " . implode(' / ', array_map(fn ($e) => $e->content, $update->entries)) . "\n"),
        default => null,
    };
}
