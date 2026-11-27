<?php

declare(strict_types=1);

namespace Acp;

use Acp\Client\ClientConnection;
use Acp\Client\FileSystemHandler;
use Acp\Client\PermissionHandler;
use Acp\Rpc\Direction;
use Acp\Rpc\ObservedTransport;
use Acp\Rpc\StdioTransport;
use Acp\Schema\Implementation;
use Amp\CancelledException;
use Amp\Future;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Closure;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\splitLines;

/**
 * An agent running as a subprocess, spoken to over stdio: the standard ACP setup.
 *
 *     $agent = AgentProcess::start('claude-agent-acp', cwd: '/path/to/project');
 *     $client = $agent->connect(permissions: new ApproveAll());
 *     // …
 *     $agent->stop();
 *
 * The agent's stderr is drained continuously (otherwise a chatty agent would block) and
 * its last lines are kept, to explain an unexpected exit.
 */
final class AgentProcess
{
    private const STDERR_LINES = 200;

    /** @var list<string> */
    private array $stderr = [];

    private ?ClientConnection $client = null;

    /** @var Future<null> */
    private readonly Future $stderrPump;

    private function __construct(
        private readonly Process $process,
        private readonly StdioTransport $transport,
        private readonly ?Closure $onStderr,
        private readonly ?string $cwd,
    ) {
        $this->stderrPump = async(function (): void {
            try {
                foreach (splitLines($this->process->getStderr()) as $line) {
                    $this->stderr[] = $line;
                    if (count($this->stderr) > self::STDERR_LINES) {
                        array_shift($this->stderr);
                    }
                    if ($this->onStderr !== null) {
                        ($this->onStderr)($line);
                    }
                }
            } catch (Throwable) {
                // The stream closes with the process.
            }
        });
    }

    /**
     * Starts the agent.
     *
     * @param list<string> $args
     * @param string|null $cwd Working directory of the agent, and of its sessions unless they name another one.
     *                         Defaults to the current directory.
     * @param array<string, string|null> $env Added to the current environment; null removes a variable
     *                                        (e.g. ['ANTHROPIC_API_KEY' => null] to use a subscription login).
     * @param Closure(string): void|null $onStderr Called with each line the agent logs.
     */
    public static function start(
        string $command,
        array $args = [],
        ?string $cwd = null,
        array $env = [],
        ?Closure $onStderr = null,
    ): self {
        $environment = getenv();
        foreach ($env as $name => $value) {
            if ($value === null) {
                unset($environment[$name]);
            } else {
                $environment[$name] = $value;
            }
        }

        $process = Process::start([$command, ...$args], $cwd, $environment);

        // ACP requires absolute session directories.
        $directory = $cwd === null ? getcwd() : realpath($cwd);

        return new self(
            $process,
            new StdioTransport($process->getStdout(), $process->getStdin()),
            $onStderr,
            $directory === false ? null : $directory,
        );
    }

    /**
     * Opens the client side of the connection. Call initialize() on it next.
     *
     * @param PermissionHandler|null $permissions Defaults to rejecting every request.
     * @param FileSystemHandler|null $fileSystem When given, the agent reads and writes files through it.
     * @param Closure(Throwable): void|null $onError Protocol errors that cannot be reported to the agent.
     * @param (callable(Direction, string): void)|null $onMessage Sees every raw message, e.g. a Dumper.
     */
    public function connect(
        ?PermissionHandler $permissions = null,
        ?FileSystemHandler $fileSystem = null,
        ?Implementation $clientInfo = null,
        ?Closure $onError = null,
        ?callable $onMessage = null,
    ): ClientConnection {
        if ($this->client !== null) {
            throw new \LogicException('This agent process is already connected.');
        }

        $transport = $onMessage === null ? $this->transport : new ObservedTransport($this->transport, $onMessage(...));

        return $this->client = new ClientConnection($transport, $permissions, $fileSystem, $clientInfo, $onError, $this->cwd);
    }

    public function pid(): int
    {
        return $this->process->getPid();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * Last lines written by the agent on stderr.
     *
     * @return list<string>
     */
    public function stderr(): array
    {
        return $this->stderr;
    }

    /**
     * Closes the connection (the agent sees its stdin close), waits for the agent to exit,
     * and kills it after the timeout. Returns its exit code.
     */
    public function stop(float $timeout = 5.0): int
    {
        if ($this->client !== null) {
            $this->client->close();
        } else {
            $this->transport->close();
        }

        try {
            $code = $this->process->join(new TimeoutCancellation($timeout));
        } catch (CancelledException) {
            $this->process->kill();
            $code = $this->process->join();
        }

        return $this->drained($code);
    }

    /**
     * Waits for the agent to exit on its own and returns its exit code.
     */
    public function wait(): int
    {
        return $this->drained($this->process->join());
    }

    /**
     * Lets the last stderr lines arrive before returning.
     */
    private function drained(int $code): int
    {
        try {
            $this->stderrPump->await(new TimeoutCancellation(1.0));
        } catch (CancelledException) {
        }

        return $code;
    }
}
