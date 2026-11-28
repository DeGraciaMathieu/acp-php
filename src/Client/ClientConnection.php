<?php

declare(strict_types=1);

namespace Acp\Client;

use Acp\Client\Permission\Outcome;
use Acp\Client\Permission\RejectAll;
use Acp\Exception\CapabilityNotSupported;
use Acp\Exception\ConnectionClosed;
use Acp\Exception\RpcError;
use Acp\Exception\UnsupportedProtocolVersion;
use Acp\Rpc\Connection;
use Acp\Rpc\Transport;
use Acp\Schema\AgentCapabilities;
use Acp\Schema\AgentMethod;
use Acp\Schema\AuthenticateRequest;
use Acp\Schema\ClientCapabilities;
use Acp\Schema\ClientMethod;
use Acp\Schema\FileSystemCapabilities;
use Acp\Schema\Implementation;
use Acp\Schema\InitializeRequest;
use Acp\Schema\InitializeResponse;
use Acp\Schema\LoadSessionRequest;
use Acp\Schema\LoadSessionResponse;
use Acp\Schema\McpServer;
use Acp\Schema\NewSessionRequest;
use Acp\Schema\NewSessionResponse;
use Acp\Schema\ReadTextFileRequest;
use Acp\Schema\RequestPermissionRequest;
use Acp\Schema\SchemaVersion;
use Acp\Schema\SessionNotification;
use Acp\Schema\WriteTextFileRequest;
use Acp\Support\Cast;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Future;
use Closure;
use JsonSerializable;
use Throwable;

use function Amp\async;

/**
 * The client side of an ACP connection: what an editor, an orchestrator or a CI job uses
 * to drive an agent.
 *
 *     $client = AgentProcess::start('claude-agent-acp')->connect();
 *     $client->initialize();
 *     $session = $client->newSession();
 *     echo $session->prompt('Explain this project')->text();
 */
final class ClientConnection
{
    public const SDK_NAME = 'acp-php';
    public const SDK_VERSION = '0.1.0-dev';

    private readonly Connection $rpc;

    private readonly PermissionHandler $permissions;

    private ?InitializeResponse $initialized = null;

    /** @var array<string, Session> */
    private array $sessions = [];

    private readonly ?string $cwd;

    /**
     * @param Closure(Throwable): void|null $onError Errors that cannot be reported to the agent (invalid messages…).
     * @param string|null $cwd Working directory of the sessions that name none. Defaults to the current directory.
     */
    public function __construct(
        Transport $transport,
        ?PermissionHandler $permissions = null,
        private readonly ?FileSystemHandler $fileSystem = null,
        private readonly ?Implementation $clientInfo = null,
        ?Closure $onError = null,
        ?string $cwd = null,
    ) {
        $this->permissions = $permissions ?? new RejectAll();
        $this->cwd = $cwd ?? (getcwd() ?: null);
        $this->rpc = new Connection(
            $transport,
            $this->handleRequest(...),
            $this->handleNotification(...),
            $onError,
        );
        $this->rpc->start();
        $this->rpc->closed()->map(function (): void {
            foreach ($this->sessions as $session) {
                $session->abort(ConnectionClosed::whileWaiting(AgentMethod::SessionPrompt->value));
            }
        })->ignore();
    }

    /**
     * Negotiates the protocol version and capabilities. Must be called first.
     *
     * Client capabilities default to what the given handlers support.
     */
    public function initialize(?ClientCapabilities $capabilities = null, ?Cancellation $cancellation = null): InitializeResponse
    {
        $request = new InitializeRequest(
            protocolVersion: SchemaVersion::PROTOCOL_VERSION,
            clientCapabilities: $capabilities ?? new ClientCapabilities(
                fs: new FileSystemCapabilities(
                    readTextFile: $this->fileSystem !== null,
                    writeTextFile: $this->fileSystem !== null,
                ),
            ),
            clientInfo: $this->clientInfo ?? new Implementation(self::SDK_NAME, self::SDK_VERSION),
        );

        $response = InitializeResponse::fromArray(
            Cast::obj($this->rpc->request(AgentMethod::Initialize->value, $request, $cancellation), 'result'),
        );

        if ($response->protocolVersion !== SchemaVersion::PROTOCOL_VERSION) {
            $this->close();
            throw UnsupportedProtocolVersion::fromAgent($response->protocolVersion, SchemaVersion::PROTOCOL_VERSION);
        }

        return $this->initialized = $response;
    }

    public function agentCapabilities(): AgentCapabilities
    {
        return $this->initialization()->agentCapabilities ?? new AgentCapabilities();
    }

    public function agentInfo(): ?Implementation
    {
        return $this->initialization()->agentInfo;
    }

    /**
     * Authenticates with one of the methods listed by initialize(), when the agent
     * answers session creation with an "authentication required" error.
     */
    public function authenticate(string $methodId, ?Cancellation $cancellation = null): void
    {
        $this->initialization();
        $this->rpc->request(AgentMethod::Authenticate->value, new AuthenticateRequest($methodId), $cancellation);
    }

    /**
     * @param string|null $cwd Absolute directory the agent works in. Defaults to the agent's directory.
     * @param list<McpServer> $mcpServers MCP servers the agent should connect to for this session.
     * @param list<string> $additionalDirectories Other absolute directories the agent may work in.
     */
    public function newSession(?string $cwd = null, array $mcpServers = [], array $additionalDirectories = [], ?Cancellation $cancellation = null): Session
    {
        $this->initialization();
        $response = NewSessionResponse::fromArray(Cast::obj($this->rpc->request(
            AgentMethod::SessionNew->value,
            new NewSessionRequest(cwd: $this->sessionDirectory($cwd), mcpServers: $mcpServers, additionalDirectories: $additionalDirectories),
            $cancellation,
        ), 'result'));

        return $this->sessions[$response->sessionId] = new Session($response->sessionId, $this->rpc, $response->modes);
    }

    /**
     * Resumes a previous session. The agent replays the conversation, available through
     * Session::history() once this returns.
     *
     * @param string|null $cwd Defaults to the agent's directory.
     * @param list<McpServer> $mcpServers
     */
    public function loadSession(string $sessionId, ?string $cwd = null, array $mcpServers = [], ?Cancellation $cancellation = null): Session
    {
        if (! $this->agentCapabilities()->loadSession) {
            throw CapabilityNotSupported::named('loadSession');
        }

        // Registered before the request: the history arrives as notifications before the response.
        $session = new Session($sessionId, $this->rpc);
        $this->sessions[$sessionId] = $session;
        $session->recordHistory(true);

        try {
            $response = LoadSessionResponse::fromArray(Cast::obj($this->rpc->request(
                AgentMethod::SessionLoad->value,
                new LoadSessionRequest(mcpServers: $mcpServers, cwd: $this->sessionDirectory($cwd), sessionId: $sessionId),
                $cancellation,
            ), 'result'));
        } catch (Throwable $e) {
            unset($this->sessions[$sessionId]);
            throw $e;
        } finally {
            $session->recordHistory(false);
        }

        $session->setModes($response->modes);

        return $session;
    }

    public function session(string $sessionId): ?Session
    {
        return $this->sessions[$sessionId] ?? null;
    }

    public function close(): void
    {
        $this->rpc->close();
    }

    public function isClosed(): bool
    {
        return $this->rpc->isClosed();
    }

    /**
     * Completes when the connection closes (agent exited, close() called…).
     *
     * @return Future<null>
     */
    public function closed(): Future
    {
        return $this->rpc->closed();
    }

    private function initialization(): InitializeResponse
    {
        return $this->initialized ?? throw CapabilityNotSupported::notInitialized();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleRequest(string $method, array $params, Cancellation $cancellation): JsonSerializable
    {
        return match (ClientMethod::tryFrom($method)) {
            ClientMethod::SessionRequestPermission => $this->requestPermission(RequestPermissionRequest::fromArray($params), $cancellation),
            ClientMethod::FsReadTextFile => $this->fileSystem()->readTextFile(ReadTextFileRequest::fromArray($params), $cancellation),
            ClientMethod::FsWriteTextFile => $this->fileSystem()->writeTextFile(WriteTextFileRequest::fromArray($params), $cancellation),
            default => throw RpcError::methodNotFound($method),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleNotification(string $method, array $params): void
    {
        if ($method !== ClientMethod::SessionUpdate->value) {
            return; // other notifications (elicitation/complete, extensions) are not handled yet
        }

        $notification = SessionNotification::fromArray($params);
        ($this->sessions[$notification->sessionId] ?? null)?->dispatch($notification->update);
    }

    private function requestPermission(RequestPermissionRequest $request, Cancellation $cancellation): JsonSerializable
    {
        $turnCancellation = ($this->sessions[$request->sessionId] ?? null)?->turnCancellation();
        if ($turnCancellation?->isRequested()) {
            return Outcome::cancelled();
        }

        $combined = $turnCancellation === null ? $cancellation : new CompositeCancellation($cancellation, $turnCancellation);
        $decision = async(fn () => $this->permissions->requestPermission($request, $combined));

        try {
            return $decision->await($combined);
        } catch (CancelledException $e) {
            // The protocol requires a "cancelled" outcome as soon as the turn is cancelled,
            // even if the handler is still waiting (for a human, for instance).
            if ($turnCancellation?->isRequested()) {
                $decision->ignore();

                return Outcome::cancelled();
            }
            throw $e;
        }
    }

    private function sessionDirectory(?string $cwd): string
    {
        return $cwd ?? $this->cwd ?? throw new \LogicException('No current directory to default to: pass the session $cwd.');
    }

    private function fileSystem(): FileSystemHandler
    {
        return $this->fileSystem ?? throw RpcError::methodNotFound('fs/*: this client does not expose a file system');
    }
}
