<?php

declare(strict_types=1);

namespace Acp\Tests\Feature;

use Acp\Exception\CapabilityNotSupported;
use Acp\Exception\ConnectionClosed;
use Acp\Exception\RpcError;
use Acp\Exception\UnsupportedProtocolVersion;
use Acp\Schema\AuthMethod;
use Acp\Testing\FakeAgent;
use Acp\Testing\FakeTurn;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionLifecycleTest extends TestCase
{
    #[Test]
    public function the_agent_introduces_itself_at_initialize(): void
    {
        $client = FakeAgent::create()->connect();

        $init = $client->initialize();

        self::assertSame('fake-agent', $init->agentInfo?->name);
        self::assertSame('fake-agent', $client->agentInfo()?->name);
        self::assertTrue($client->agentCapabilities()->loadSession);
    }

    #[Test]
    public function sessions_require_initialize(): void
    {
        $this->expectException(CapabilityNotSupported::class);
        FakeAgent::create()->connect()->newSession(cwd: '/tmp');
    }

    #[Test]
    public function authentication_when_the_agent_requires_it(): void
    {
        $client = FakeAgent::create()->requiringAuthentication()->connect();
        $init = $client->initialize();

        try {
            $client->newSession(cwd: '/tmp');
            self::fail('Authentication should have been required.');
        } catch (RpcError $e) {
            self::assertTrue($e->isAuthRequired());
        }

        $client->authenticate($init->authMethods[0] instanceof AuthMethod\Agent ? $init->authMethods[0]->id : '');
        self::assertStringStartsWith('fake-session-', $client->newSession(cwd: '/tmp')->id);
    }

    #[Test]
    public function an_unsupported_protocol_version_closes_the_connection(): void
    {
        $client = FakeAgent::create()->speakingProtocol(0)->connect();

        try {
            $client->initialize();
            self::fail('The version mismatch should have been detected.');
        } catch (UnsupportedProtocolVersion) {
        }

        self::assertTrue($client->isClosed());
    }

    #[Test]
    public function a_closed_connection_fails_the_running_turn(): void
    {
        $client = FakeAgent::create()
            ->onPrompt(function (FakeTurn $turn): null {
                $turn->untilCancelled();

                return null;
            })
            ->connect();
        $client->initialize();
        $turn = $client->newSession(cwd: '/tmp')->prompt('hi');

        $client->close();

        $this->expectException(ConnectionClosed::class);
        $turn->wait();
    }
}
