<?php

declare(strict_types=1);

namespace Acp\Client\FileSystem;

use Acp\Exception\RpcError;
use Acp\Schema\ErrorCode;
use Acp\Schema\ReadTextFileRequest;
use Acp\Schema\ReadTextFileResponse;
use Acp\Schema\WriteTextFileRequest;
use Acp\Schema\WriteTextFileResponse;
use Amp\Cancellation;

/**
 * Serves the agent's file reads and writes from the local disk, confined to a root directory.
 *
 * Any path outside the root (absolute elsewhere, "..", symlink escaping it) is refused.
 */
final class LocalFileSystem implements FileSystemHandler
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || ! is_dir($real)) {
            throw new \InvalidArgumentException(sprintf('Root directory "%s" does not exist.', $root));
        }
        $this->root = rtrim($real, DIRECTORY_SEPARATOR);
    }

    public function readTextFile(ReadTextFileRequest $request, Cancellation $cancellation): ReadTextFileResponse
    {
        $path = $this->resolve($request->path);
        if (! is_file($path)) {
            throw new RpcError(ErrorCode::ResourceNotFound->value, sprintf('File not found: %s', $request->path));
        }

        $content = (string) file_get_contents($path);

        if ($request->line !== null || $request->limit !== null) {
            $lines = preg_split('/(?<=\n)/', $content) ?: [];
            $offset = max(0, ($request->line ?? 1) - 1);
            $content = implode('', array_slice($lines, $offset, $request->limit));
        }

        return new ReadTextFileResponse($content);
    }

    public function writeTextFile(WriteTextFileRequest $request, Cancellation $cancellation): WriteTextFileResponse
    {
        $path = $this->resolve($request->path);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw RpcError::internal(sprintf('Cannot create directory for %s', $request->path));
        }
        if (file_put_contents($path, $request->content) === false) {
            throw RpcError::internal(sprintf('Cannot write %s', $request->path));
        }

        return new WriteTextFileResponse();
    }

    /**
     * Absolute path inside the root, or an error.
     */
    private function resolve(string $path): string
    {
        if (! str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            throw RpcError::invalidParams(sprintf('ACP file paths must be absolute: %s', $path));
        }

        // Normalize without requiring the file to exist, then check the closest existing parent
        // with realpath() so that symlinks cannot escape the root either.
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        $normalized = (str_starts_with($path, '/') ? '/' : '') . implode('/', $parts);

        $existing = $normalized;
        while (! file_exists($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        $real = realpath($existing);
        $suffix = substr($normalized, strlen($existing));
        $candidate = ($real === false ? $existing : $real) . $suffix;

        if ($candidate !== $this->root && ! str_starts_with($candidate, $this->root . DIRECTORY_SEPARATOR)) {
            throw new RpcError(ErrorCode::InvalidParams->value, sprintf('Access outside of %s refused: %s', $this->root, $path));
        }

        return $candidate;
    }
}
