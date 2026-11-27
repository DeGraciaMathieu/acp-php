<?php

declare(strict_types=1);

namespace Acp\Testing;

use Acp\Client\ClientConnection;
use Acp\Client\FileSystemHandler;
use Acp\Client\PermissionHandler;
use Acp\Exception\RpcError;
use Acp\Rpc\Connection;
use Acp\Rpc\Direction;
use Acp\Rpc\InMemoryTransport;
use Acp\Rpc\ObservedTransport;
use Acp\Rpc\StdioTransport;
use Acp\Rpc\Transport;
use Acp\Schema\AgentCapabilities;
use Acp\Schema\AgentMethod;
use Acp\Schema\AuthenticateResponse;
use Acp\Schema\AuthMethod;
use Acp\Schema\ClientMethod;
use Acp\Schema\ErrorCode;
use Acp\Schema\Implementation;
use Acp\Schema\InitializeRequest;
use Acp\Schema\InitializeResponse;
use Acp\Schema\LoadSessionRequest;
use Acp\Schema\LoadSessionResponse;
use Acp\Schema\NewSessionResponse;
use Acp\Schema\PromptCapabilities;
use Acp\Schema\PromptRequest;
use Acp\Schema\PromptResponse;
use Acp\Schema\SchemaVersion;
use Acp\Schema\SessionMode;
use Acp\Schema\SessionModeState;
use Acp\Schema\SessionNotification;
use Acp\Schema\SessionUpdate;
use Acp\Schema\SetSessionModeRequest;
use Acp\Schema\SetSessionModeResponse;
use Acp\Schema\StopReason;
use Amp\Cancellation;
use Closure;
use JsonSerializable;

use function Amp\ByteStream\getStdin;
use function Amp\ByteStream\getStdout;

/**
 * A scriptable ACP agent, to test clients without running a real one.
 *
 *     $client = FakeAgent::create()
 *         ->onPrompt(function (FakeTurn $turn) {
 *             $turn->toolCall('call_1', 'Run the tests', ToolKind::Execute);
 *             if ($turn->requestPermission('call_1', 'npm test') === null) {
 *                 return StopReason::Cancelled;
 *             }
 *             $turn->say('All green.');
 *         })
 *         ->connect(permissions: new ApproveAll());
 *
 * It runs in memory with connect(), or as a real subprocess with runOverStdio().
 */
final class FakeAgent
{
    /** @var Closure(FakeTurn): (StopReason|string|null) */
    private Closure $onPrompt;

    private AgentCapabilities $capabilities;

    private int $protocolVersion = SchemaVersion::PROTOCOL_VERSION;

    private bool $requiresAuthentication = false;

    private bool $authenticated = false;

    private int $sessionCount = 0;

    /** @var array<string, list<SessionUpdate>> */
    private array $histories = [];

    /** @var array<string, FakeTurn> */
    private array $turns = [];

    /** @var list<array{method: string, params: array<string, mixed>}> */
    private array $received = [];

    private function __construct()
    {
        $this->onPrompt = static function (FakeTurn $turn): StopReason {
            $turn->say('OK');

            return StopReason::EndTurn;
        };
        $this->capabilities = new AgentCapabilities(
            loadSession: true,
            promptCapabilities: new PromptCapabilities(embeddedContext: true),
        );
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * Plays the agent's part of each prompt turn. Return a stop reason, or nothing for
     * "end_turn" ("cancelled" when the client cancelled meanwhile).
     *
     * @param Closure(FakeTurn): (StopReason|string|null) $handler
     */
    public function onPrompt(Closure $handler): self
    {
        $this->onPrompt = $handler;

        return $this;
    }

    public function withCapabilities(AgentCapabilities $capabilities): self
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    /**
     * Session creation fails with "authentication required" until authenticate("fake-login").
     */
    public function requiringAuthentication(): self
    {
        $this->requiresAuthentication = true;

        return $this;
    }

    public function speakingProtocol(int $version): self
    {
        $this->protocolVersion = $version;

        return $this;
    }

    /**
     * Connects a client to this agent, in memory.
     *
     * @param Closure(\Throwable): void|null $onError
     * @param (callable(Direction, string): void)|null $onMessage Sees every raw message on the client side, e.g. a Dumper.
     */
    public function connect(
        ?PermissionHandler $permissions = null,
        ?FileSystemHandler $fileSystem = null,
        ?Closure $onError = null,
        ?callable $onMessage = null,
    ): ClientConnection {
        [$clientSide, $agentSide] = InMemoryTransport::pair();
        $this->serve($agentSide);
        $transport = $onMessage === null ? $clientSide : new ObservedTransport($clientSide, $onMessage(...));

        return new ClientConnection($transport, $permissions, $fileSystem, onError: $onError);
    }

    /**
     * Serves a client over any transport.
     */
    public function serve(Transport $transport): Connection
    {
        $rpc = null;
        $rpc = new Connection(
            $transport,
            function (string $method, array $params, Cancellation $cancellation) use (&$rpc): JsonSerializable {
                $this->received[] = ['method' => $method, 'params' => $params];

                /** @var Connection $rpc */
                return $this->handle($rpc, $method, $params);
            },
            function (string $method, array $params): void {
                $this->received[] = ['method' => $method, 'params' => $params];
                if ($method === AgentMethod::SessionCancel->value && is_string($params['sessionId'] ?? null)) {
                    ($this->turns[$params['sessionId']] ?? null)?->cancel();
                }
            },
        );
        $rpc->start();

        return $rpc;
    }

    /**
     * Serves a client over this process's stdin/stdout, until the client disconnects.
     * Use it in a small PHP script to get a real agent subprocess.
     */
    public function runOverStdio(): void
    {
        $this->serve(new StdioTransport(getStdin(), getStdout()))->closed()->await();
    }

    /**
     * Messages received from the client, optionally for one method.
     *
     * @return list<array<string, mixed>> params of each message
     */
    public function received(?string $method = null): array
    {
        $messages = [];
        foreach ($this->received as $message) {
            if ($method === null || $message['method'] === $method) {
                $messages[] = $message['params'];
            }
        }

        return $messages;
    }

    /**
     * @internal
     */
    public function record(string $sessionId, SessionUpdate $update): void
    {
        $this->histories[$sessionId][] = $update;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handle(Connection $rpc, string $method, array $params): JsonSerializable
    {
        switch (AgentMethod::tryFrom($method)) {
            case AgentMethod::Initialize:
                $request = InitializeRequest::fromArray($params);

                return new InitializeResponse(
                    protocolVersion: min($request->protocolVersion, $this->protocolVersion),
                    agentCapabilities: $this->capabilities,
                    authMethods: $this->requiresAuthentication ? [new AuthMethod\Agent('fake-login', 'Fake login')] : [],
                    agentInfo: new Implementation('fake-agent', ClientConnection::SDK_VERSION),
                );

            case AgentMethod::Authenticate:
                $this->authenticated = true;

                return new AuthenticateResponse();

            case AgentMethod::SessionNew:
                if ($this->requiresAuthentication && ! $this->authenticated) {
                    throw new RpcError(ErrorCode::AuthenticationRequired->value, 'Authentication required');
                }
                $sessionId = 'fake-session-' . ++$this->sessionCount;
                $this->histories[$sessionId] = [];

                return new NewSessionResponse(sessionId: $sessionId, modes: $this->modes('code'));

            case AgentMethod::SessionLoad:
                $request = LoadSessionRequest::fromArray($params);
                if (! isset($this->histories[$request->sessionId])) {
                    throw new RpcError(ErrorCode::ResourceNotFound->value, 'Unknown session ' . $request->sessionId);
                }
                foreach ($this->histories[$request->sessionId] as $update) {
                    $rpc->notify(ClientMethod::SessionUpdate->value, new SessionNotification($request->sessionId, $update));
                }

                return new LoadSessionResponse(modes: $this->modes('code'));

            case AgentMethod::SessionPrompt:
                $request = PromptRequest::fromArray($params);
                if (! isset($this->histories[$request->sessionId])) {
                    throw new RpcError(ErrorCode::ResourceNotFound->value, 'Unknown session ' . $request->sessionId);
                }
                foreach ($request->prompt as $block) {
                    $this->record($request->sessionId, new SessionUpdate\UserMessageChunk($block));
                }
                $turn = new FakeTurn($request, $rpc, $this);
                $this->turns[$request->sessionId] = $turn;
                try {
                    $stopReason = ($this->onPrompt)($turn);
                } finally {
                    unset($this->turns[$request->sessionId]);
                }

                return new PromptResponse($stopReason ?? ($turn->isCancelled() ? StopReason::Cancelled : StopReason::EndTurn));

            case AgentMethod::SessionSetMode:
                $request = SetSessionModeRequest::fromArray($params);
                $update = new SessionUpdate\CurrentModeUpdate($request->modeId);
                $this->record($request->sessionId, $update);
                $rpc->notify(ClientMethod::SessionUpdate->value, new SessionNotification($request->sessionId, $update));

                return new SetSessionModeResponse();

            default:
                throw RpcError::methodNotFound($method);
        }
    }

    private function modes(string $current): SessionModeState
    {
        return new SessionModeState($current, [new SessionMode('ask', 'Ask'), new SessionMode('code', 'Code')]);
    }
}
