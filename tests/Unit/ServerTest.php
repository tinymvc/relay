<?php

namespace Tests\Unit;

use React\EventLoop\Loop;
use Spark\Relay\Api;
use Spark\Relay\App;
use Spark\Relay\Channels;
use Spark\Relay\Protocol;
use Spark\Relay\Server;
use Spark\Testing\TestCase;

class ServerTest extends TestCase
{
    private App $app;
    private Channels $channels;
    private Api $api;
    private Server $server;

    protected function setUp(): void
    {
        $this->app = new App(
            appId: '1',
            key: 'k1',
            secret: 's1',
            allowedOrigins: ['http://localhost:3000', 'https://*.example.com']
        );
        $this->channels = new Channels();
        $this->api = new Api($this->channels, [$this->app->appId => $this->app]);
        $this->server = new Server(
            $this->channels,
            [$this->app->appId => $this->app],
            $this->api,
            [
                'host' => '127.0.0.1',
                'port' => 8089,
                'activity_timeout' => 10,
                'pong_timeout' => 10,
                'max_connections' => 2,
                'memory_limit_mb' => 256,
            ],
            Loop::get()
        );
    }

    public function testOriginCheckAllowsConfiguredHosts(): void
    {
        // Use reflection to test private isOriginAllowed
        $ref = new \ReflectionMethod(Server::class, 'isOriginAllowed');

        $mockReq1 = new \GuzzleHttp\Psr7\ServerRequest('GET', '/app/k1', ['Origin' => 'http://localhost:3000']);
        $this->assertTrue($ref->invoke($this->server, $this->app, $mockReq1));

        $mockReq2 = new \GuzzleHttp\Psr7\ServerRequest('GET', '/app/k1', ['Origin' => 'https://sub.example.com']);
        $this->assertTrue($ref->invoke($this->server, $this->app, $mockReq2));

        $mockReqBad = new \GuzzleHttp\Psr7\ServerRequest('GET', '/app/k1', ['Origin' => 'http://evil.com']);
        $this->assertFalse($ref->invoke($this->server, $this->app, $mockReqBad));

        $mockReqMissing = new \GuzzleHttp\Psr7\ServerRequest('GET', '/app/k1');
        $this->assertFalse($ref->invoke($this->server, $this->app, $mockReqMissing));
    }

    public function testWildcardOriginAllowsAnyOrigin(): void
    {
        $wildcardApp = new App('2', 'k2', 's2', allowedOrigins: ['*']);
        $ref = new \ReflectionMethod(Server::class, 'isOriginAllowed');

        $mockReq1 = new \GuzzleHttp\Psr7\ServerRequest('GET', '/app/k2', ['Origin' => 'http://anything.com']);
        $this->assertTrue($ref->invoke($this->server, $wildcardApp, $mockReq1));

        $mockReqMissing = new \GuzzleHttp\Psr7\ServerRequest('GET', '/app/k2');
        $this->assertTrue($ref->invoke($this->server, $wildcardApp, $mockReqMissing));
    }

    public function testOriginsCannotHideAnUntrustedHostInUrlComponents(): void
    {
        $method = new \ReflectionMethod(Server::class, 'isOriginAllowed');
        foreach ([
            'https://evil.test/path.example.com',
            'https://evil.test#sub.example.com',
            'https://allowed.example.com@evil.test',
            'ftp://sub.example.com',
            'https://sub.example.com/path',
        ] as $origin) {
            $request = new \GuzzleHttp\Psr7\ServerRequest('GET', '/app/k1', ['Origin' => $origin]);
            $this->assertFalse($method->invoke($this->server, $this->app, $request));
        }
    }

    public function testActivityScannerClosesOnPongTimeout(): void
    {
        // Simulate a connection that was pinged and hasn't responded within pong_timeout
        $socket = new FakeConnection('test.1', $this->app);
        // Let's create a real Connection wrapping the FakeConnection stream
        $conn = new \Spark\Relay\Connection(
            new class extends \React\Socket\Connection implements \React\Socket\ConnectionInterface {
                public function __construct() {}
                public function getRemoteAddress(): ?string { return '127.0.0.1'; }
                public function getLocalAddress(): ?string { return '127.0.0.1'; }
                public function isReadable(): bool { return true; }
                public function isWritable(): bool { return true; }
                public function pause(): void {}
                public function resume(): void {}
                public function pipe(\React\Stream\WritableStreamInterface $dest, array $options = []): \React\Stream\WritableStreamInterface { return $dest; }
                public function write($data): bool { return true; }
                public function end($data = null): void {}
                public function close(): void {}
            },
            'test.1',
            $this->app,
            $this->channels
        );

        $connProp = new \ReflectionProperty(Server::class, 'connections');
        $connProp->setValue($this->server, ['test.1' => $conn]);

        $this->assertEquals(1, $this->server->getConnectionCount());

        // Set pingSentAt in the past > pong_timeout
        $conn->setPingSentAt(microtime(true) - 15);

        $scanMethod = new \ReflectionMethod(Server::class, 'scanActivity');
        $scanMethod->invoke($this->server);

        // Connection should have been closed and removed from server
        $this->assertTrue($conn->isClosed());
        $this->assertEquals(0, $this->server->getConnectionCount());
    }
}
