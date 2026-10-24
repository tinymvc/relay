<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\StreamSelectLoop;
use Spark\Relay\{Api, App, Channels, Connection, Protocol, Server};
use Spark\Testing\TestCase;

class TransportRegressionTest extends TestCase
{
    private StreamSelectLoop $loop;
    private App $app;
    private Channels $channels;
    private array $streams = [];

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
        $this->app = new App('1', 'key', 'secret', clientEvents: true, maxMessageSize: 256, maxConnections: 1);
        $this->channels = new Channels();
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            $stream->close();
        }
    }

    private function stream(): FakeStream
    {
        return $this->streams[] = new FakeStream();
    }

    private function connection(FakeStream $stream, int $outputLimit = 1048576): Connection
    {
        return new Connection($stream, '1.1', $this->app, $this->channels, maxOutboundBufferSize: $outputLimit, loop: $this->loop);
    }

    private function frame(string $payload, int $opcode = Frame::OP_TEXT, bool $final = true): string
    {
        $frame = new Frame($payload, $final, $opcode);
        $frame->maskPayload();
        return $frame->getContents();
    }

    private function lastFrame(FakeStream $stream): Frame
    {
        $frame = new Frame();
        $frame->addBuffer(end($stream->writes));
        return $frame;
    }

    private function server(array $config = []): Server
    {
        $apps = ['1' => $this->app];
        return new Server($this->channels, $apps, new Api($this->channels, $apps), $config, $this->loop);
    }

    private function accept(Server $server, FakeStream $stream): void
    {
        (new \ReflectionMethod(Server::class, 'handleNewConnection'))->invoke($server, $stream);
    }

    private function handshake(string $key = 'key'): string
    {
        return "GET /app/{$key}?protocol=7 HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n";
    }

    public function testPrivateCacheNeverReplaysBeforeSuccessfulAuthorization(): void
    {
        $stream = $this->stream();
        $connection = $this->connection($stream);
        $this->channels->publish($this->app, 'private-cache-room', 'secret-state', '{"secret":true}');
        $connection->onData($this->frame(json_encode([
            'event' => 'pusher:subscribe',
            'data' => ['channel' => 'private-cache-room', 'auth' => 'key:invalid'],
        ])));
        $this->assertSame('pusher:error', json_decode($this->lastFrame($stream)->getPayload(), true)['event']);
        $this->assertFalse($this->channels->occupied('1', 'private-cache-room'));
        $this->assertCount(1, $stream->writes);
        $connection->onData($this->frame(json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'private-cache-room',
                'auth' => \Spark\Relay\Signature::channelAuth($this->app, '1.1', 'private-cache-room'),
            ],
        ])));
        $this->assertCount(3, $stream->writes);
        $this->assertSame('secret-state', json_decode($this->lastFrame($stream)->getPayload(), true)['event']);
    }

    public function testServerRecordsUnsubscribedConnectionsAndMessageCounters(): void
    {
        $server = $this->server();
        $stream = $this->stream();
        $this->accept($server, $stream);
        $stream->emit('data', [$this->handshake()]);
        $stats = $server->api->metrics->snapshot('1');
        $this->assertSame(1, $stats['connections']);
        $this->assertSame(1, $stats['messages_sent']);
        $this->assertSame(0, $server->channels->connectionCount('1'));
        $ping = '{"event":"pusher:ping","data":{}}';
        $stream->emit('data', [$this->frame($ping)]);
        $stats = $server->api->metrics->snapshot('1');
        $this->assertSame(1, $stats['messages_received']);
        $this->assertSame(strlen($ping), $stats['bytes_received']);
        $this->assertSame(2, $stats['messages_sent']);
        $stream->close();
        $this->assertSame(0, $server->api->metrics->snapshot('1')['connections']);
        $this->assertSame(1, $server->api->metrics->snapshot('1')['connections_total']);
    }

    public function testHealthIsMinimalAndStatsAreNotPublic(): void
    {
        $server = $this->server();
        $health = $this->stream();
        $this->accept($server, $health);
        $health->emit('data', ["GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n"]);
        $this->assertSame('{"status":"ok"}', explode("\r\n\r\n", $health->writes[0], 2)[1]);
        $stats = $this->stream();
        $this->accept($server, $stats);
        $stats->emit('data', ["GET /apps/1/stats HTTP/1.1\r\nHost: localhost\r\n\r\n"]);
        $this->assertTrue(str_starts_with($stats->writes[0], 'HTTP/1.1 401'));
    }

    public function testOversizedFrameRejectedBeforePayloadArrives(): void
    {
        $stream = $this->stream();
        $connection = $this->connection($stream);
        $connection->onData(substr($this->frame(str_repeat('a', 1000)), 0, 140));
        $this->assertTrue($connection->isClosed());
        $this->assertSame(1009, unpack('n', $this->lastFrame($stream)->getPayload())[1]);
        $this->assertCount(1, $stream->writes);
    }

    public function testFragmentedMessageLimitIsCumulative(): void
    {
        $stream = $this->stream();
        $connection = $this->connection($stream);
        $connection->onData($this->frame(str_repeat('a', 200), final: false));
        $this->assertFalse($connection->isClosed());
        $connection->onData($this->frame(str_repeat('b', 100), Frame::OP_CONTINUE));
        $this->assertTrue($connection->isClosed());
        $this->assertSame(1009, unpack('n', $this->lastFrame($stream)->getPayload())[1]);
    }

    public function testEmptyFragmentsCannotGrowMetadataWithoutBound(): void
    {
        $stream = $this->stream();
        $connection = $this->connection($stream);
        $connection->onData($this->frame('', final: false));
        $connection->onData(str_repeat($this->frame('', Frame::OP_CONTINUE, false), 1024));
        $this->assertTrue($connection->isClosed());
        $this->assertSame(1009, unpack('n', $this->lastFrame($stream)->getPayload())[1]);
    }

    public function testUnmaskedMessageClosesExactlyOnceAndIgnoresFollowingSubscribe(): void
    {
        $stream = $this->stream();
        $connection = $this->connection($stream);
        $connection->onData((new Frame('{}'))->getContents() . $this->frame('{"event":"pusher:subscribe","data":{"channel":"news"}}'));
        $this->assertCount(1, $stream->writes);
        $this->assertSame(1002, unpack('n', $this->lastFrame($stream)->getPayload())[1]);
        $this->assertFalse($this->channels->occupied('1', 'news'));
    }

    public function testWebSocketPongAcknowledgesHeartbeat(): void
    {
        $stream = $this->stream();
        $connection = $this->connection($stream);
        $connection->setPingSentAt(microtime(true) - 10);
        $connection->onData($this->frame('', Frame::OP_PONG));
        $this->assertNull($connection->getPingSentAt());
        $this->assertFalse($connection->isClosed());
    }

    public function testMalformedEventAndSubscriptionTypesStayContained(): void
    {
        $stream = $this->stream();
        $connection = $this->connection($stream);
        foreach ([
            ['event' => []],
            ['event' => 'pusher:subscribe', 'data' => ['channel' => []]],
            ['event' => 'pusher:subscribe', 'data' => ['channel' => 'private-room', 'auth' => [], 'channel_data' => 1]]
        ] as $payload) {
            $connection->onData($this->frame(json_encode($payload)));
            $this->assertFalse($connection->isClosed());
            $this->assertSame('pusher:error', json_decode($this->lastFrame($stream)->getPayload(), true)['event']);
        }
        $this->assertSame(0, $this->channels->connectionCount('1'));
    }

    public function testSlowConsumerDisconnectsAndCleansSubscriptions(): void
    {
        $stream = $this->stream();
        $connection = $this->connection($stream, 256);
        $this->channels->subscribe($connection, 'news');
        $stream->backpressure = true;
        $connection->send(str_repeat('x', 200));
        $connection->send(str_repeat('x', 200));
        $this->assertTrue($stream->closed);
        $this->assertFalse($this->channels->occupied('1', 'news'));
    }

    public function testDrainResetsOutputBudget(): void
    {
        $stream = $this->stream();
        $connection = $this->connection($stream, 256);
        $stream->backpressure = true;
        $connection->send(str_repeat('x', 200));
        $stream->emit('drain');
        $connection->send(str_repeat('x', 200));
        $this->assertFalse($connection->isClosed());
    }

    public function testWhispersAreRateLimited(): void
    {
        $stream = $this->stream();
        $connection = $this->connection($stream);
        $this->channels->subscribe($connection, 'private-room');
        $peer = new FakeConnection('2.2', $this->app);
        $this->channels->subscribe($peer, 'private-room');
        $message = $this->frame('{"event":"client-test","channel":"private-room","data":{}}');
        $connection->onData(str_repeat($message, 11));
        $this->assertCount(11, $peer->sent); // subscription plus ten events
        $error = json_decode($this->lastFrame($stream)->getPayload(), true);
        $this->assertSame(4301, json_decode($error['data'], true)['code']);
    }

    public function testOversizedHttpBodyRejectedFromHeadersBeforeContinue(): void
    {
        $stream = $this->stream();
        $this->accept($this->server(['max_request_size' => 100]), $stream);
        $stream->emit('data', ["POST /apps/1/events HTTP/1.1\r\nHost: localhost\r\nContent-Length: 101\r\nExpect: 100-continue\r\n\r\n"]);
        $this->assertStringStartsWith('HTTP/1.1 413', $stream->writes[0]);
        $this->assertCount(1, $stream->writes);
    }

    public function testHeaderLimitAppliesBeforeTerminator(): void
    {
        $stream = $this->stream();
        $this->accept($this->server(['max_header_size' => 64]), $stream);
        $stream->emit('data', ['GET / HTTP/1.1' . str_repeat('x', 100)]);
        $this->assertStringStartsWith('HTTP/1.1 431', $stream->writes[0]);
    }

    public function testAmbiguousHttpFramingIsRejected(): void
    {
        foreach (["Content-Length: -1", "Content-Length: 1, 1", "Content-Length: 0\r\nContent-Length: 1", 'Transfer-Encoding: chunked'] as $header) {
            $stream = $this->stream();
            $this->accept($this->server(), $stream);
            $stream->emit('data', ["POST /apps/1/events HTTP/1.1\r\nHost: localhost\r\n{$header}\r\n\r\n"]);
            $this->assertStringStartsWith('HTTP/1.1 400', $stream->writes[0]);
        }
    }

    public function testPartialHttpRequestExpires(): void
    {
        $stream = $this->stream();
        $this->accept($this->server(['request_timeout' => 0.002]), $stream);
        $stream->emit('data', ['GET /']);
        $this->loop->run();
        $this->assertTrue($stream->closed);
    }

    public function testPendingConnectionLimitIsReleasedOnClose(): void
    {
        $server = $this->server(['max_pending_connections' => 1]);
        $first = $this->stream();
        $this->accept($server, $first);
        $second = $this->stream();
        $this->accept($server, $second);
        $this->assertTrue($second->closed);
        $first->close();
        $third = $this->stream();
        $this->accept($server, $third);
        $this->assertFalse($third->closed);
    }

    public function testPerAppLimitCountsUnsubscribedSocketsAndReleasesOnDisconnect(): void
    {
        $server = $this->server();
        $first = $this->stream();
        $this->accept($server, $first);
        $first->emit('data', [$this->handshake()]);
        $this->assertSame(1, $server->getConnectionCount());
        $second = $this->stream();
        $this->accept($server, $second);
        $second->emit('data', [$this->handshake()]);
        $this->assertTrue($second->closed);
        $this->assertSame(4004, unpack('n', $this->lastFrame($second)->getPayload())[1]);
        $first->close();
        $third = $this->stream();
        $this->accept($server, $third);
        $third->emit('data', [$this->handshake()]);
        $this->assertSame(1, $server->getConnectionCount());
    }

    public function testInvalidUpgradeDoesNotAppendWebSocketFrames(): void
    {
        $stream = $this->stream();
        $this->accept($this->server(), $stream);
        $stream->emit('data', [str_replace('Sec-WebSocket-Version: 13', 'Sec-WebSocket-Version: 12', $this->handshake('unknown'))]);
        $this->assertCount(1, $stream->writes);
        $this->assertStringStartsWith('HTTP/1.1 426', $stream->writes[0]);
    }

    public function testSplitSignedHttpBodyPublishesOnceDespiteLaterData(): void
    {
        $peer = new FakeConnection('2.2', $this->app);
        $this->channels->subscribe($peer, 'news');
        $body = json_encode(['name' => 'test', 'channel' => 'news', 'data' => '{}']);
        $query = Pusher::build_auth_query_params('key', 'secret', 'POST', '/apps/1/events', ['body_md5' => md5($body)]);
        $stream = $this->stream();
        $stream->backpressure = true;
        $this->accept($this->server(), $stream);
        $stream->emit('data', ['POST /apps/1/events?' . http_build_query($query) . " HTTP/1.1\r\nHost: localhost\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . substr($body, 0, 5)]);
        $this->assertCount(1, $peer->sent);
        $stream->emit('data', [substr($body, 5)]);
        $stream->emit('data', [$body]);
        $this->assertCount(2, $peer->sent);
        $this->assertCount(1, $stream->writes);
        $this->assertStringStartsWith('HTTP/1.1 200', $stream->writes[0]);
    }
}
