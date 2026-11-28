<?php

declare(strict_types=1);

namespace Acp\Client\FileSystem;

use Acp\Schema\ReadTextFileRequest;
use Acp\Schema\ReadTextFileResponse;
use Acp\Schema\WriteTextFileRequest;
use Acp\Schema\WriteTextFileResponse;
use Amp\Cancellation;

/**
 * Gives the agent access to files through the client.
 *
 * When a client provides one, it advertises the fs.readTextFile and fs.writeTextFile
 * capabilities, and the agent may read and write through it instead of touching the disk
 * itself: useful to confine an agent, record its writes, or serve unsaved buffers.
 */
interface FileSystemHandler
{
    public function readTextFile(ReadTextFileRequest $request, Cancellation $cancellation): ReadTextFileResponse;

    public function writeTextFile(WriteTextFileRequest $request, Cancellation $cancellation): WriteTextFileResponse;
}
