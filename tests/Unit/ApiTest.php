<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Spark\Relay\Api;
use Spark\Relay\App;
use Spark\Relay\Channels;
use Spark\Testing\TestCase;

class ApiTest extends TestCase
{
    private App $app;
    private Channels $channels;
    private Api $api;

    protected function setUp(): void
    {
        $this->app = new App(
            appId: '10',
            key: 'apikey',
            secret: 'apisecret'
        );
        $this->channels = new Channels();
        $this->api = new Api($this->channels, [$this->app->appId => $this->app]);
    }

    private function signRequest(string $method, string $path, array $queryParams, string $body): array
    {
        return Pusher::build_auth_query_params(
            $this->app->key,
            $this->app->secret,
            $method,
            $path,
            $queryParams,
            '1.0',
            time()
        );
    }

    public function testPostEventsPublishesAndReplies200(): void
    {
        $conn = new FakeConnection('1.1', $this->app);
        $this->channels->subscribe($conn, 'news');

        $body = json_encode([
            'name' => 'headline',
            'channel' => 'news',
            'data' => '{"title":"Breaking News"}',
        ]);

        $path = '/apps/10/events';
        $query = $this->signRequest('POST', $path, ['body_md5' => md5($body)], $body);

        [$status, $headers, $respBody] = $this->api->handle('POST', $path, $query, $body);

        $this->assertEquals(200, $status);
        $this->assertEquals('application/json', $headers['Content-Type']);
        $this->assertEquals('{}', $respBody);

        $last = $conn->lastSentDecoded();
        $this->assertEquals('headline', $last['event']);
        $this->assertEquals('news', $last['channel']);
        $this->assertEquals('{"title":"Breaking News"}', $last['data']);
    }

    public function testPostEventsRejectsReservedPrefixes(): void
    {
        $body = json_encode([
            'name' => 'pusher:spoofed',
            'channel' => 'news',
            'data' => '{}',
        ]);
        $path = '/apps/10/events';
        $query = $this->signRequest('POST', $path, ['body_md5' => md5($body)], $body);

        [$status, , $respBody] = $this->api->handle('POST', $path, $query, $body);
        $this->assertEquals(400, $status);
        $this->assertStringContainsString('reserved pusher prefixes', $respBody);
    }

    public function testPostEventsRejectsOversizedPayload(): void
    {
        $oversized = str_repeat('X', 10241);
        $body = json_encode([
            'name' => 'big-event',
            'channel' => 'news',
            'data' => $oversized,
        ]);
        $path = '/apps/10/events';
        $query = $this->signRequest('POST', $path, ['body_md5' => md5($body)], $body);

        [$status, , $respBody] = $this->api->handle('POST', $path, $query, $body);
        $this->assertEquals(400, $status);
        $this->assertStringContainsString('exceeds maximum size limit', $respBody);
    }

    public function testPostBatchEventsAtomicRejection(): void
    {
        $conn = new FakeConnection('1.1', $this->app);
        $this->channels->subscribe($conn, 'ch1');
        $connSentBefore = count($conn->sent);

        // One valid item, one invalid item (reserved name)
        $body = json_encode([
            'batch' => [
                ['name' => 'valid-event', 'channel' => 'ch1', 'data' => '{}'],
                ['name' => 'pusher:invalid', 'channel' => 'ch1', 'data' => '{}'],
            ],
        ]);
        $path = '/apps/10/batch_events';
        $query = $this->signRequest('POST', $path, ['body_md5' => md5($body)], $body);

        [$status, , $respBody] = $this->api->handle('POST', $path, $query, $body);

        // Entire batch MUST be rejected
        $this->assertEquals(400, $status);
        // And the valid event must NOT have been published!
        $this->assertCount($connSentBefore, $conn->sent);
    }

    public function testPostBatchEventsPublishesAllOnSuccess(): void
    {
        $conn = new FakeConnection('1.1', $this->app);
        $this->channels->subscribe($conn, 'ch1');
        $this->channels->subscribe($conn, 'ch2');

        $body = json_encode([
            'batch' => [
                ['name' => 'evt1', 'channel' => 'ch1', 'data' => '{"m":1}'],
                ['name' => 'evt2', 'channel' => 'ch2', 'data' => '{"m":2}'],
            ],
        ]);
        $path = '/apps/10/batch_events';
        $query = $this->signRequest('POST', $path, ['body_md5' => md5($body)], $body);

        [$status, , $respBody] = $this->api->handle('POST', $path, $query, $body);
        $this->assertEquals(200, $status);
        $this->assertEquals('{}', $respBody);

        // Both published
        $sentEvents = array_map(fn ($f) => json_decode($f, true)['event'], $conn->sent);
        $this->assertContains('evt1', $sentEvents);
        $this->assertContains('evt2', $sentEvents);
    }

    public function testGetChannelsReturnsObjectEvenWhenEmpty(): void
    {
        $path = '/apps/10/channels';
        $query = $this->signRequest('GET', $path, [], '');

        [$status, , $respBody] = $this->api->handle('GET', $path, $query, '');
        $this->assertEquals(200, $status);
        $this->assertEquals('{"channels":{}}', $respBody);
    }

    public function testGetChannelsWithUserCountRejectsNonPresencePrefix(): void
    {
        $path = '/apps/10/channels';
        // Requesting user_count with public prefix filter
        $query = $this->signRequest('GET', $path, [
            'info' => 'user_count',
            'filter_by_prefix' => 'public-',
        ], '');

        [$status, , $respBody] = $this->api->handle('GET', $path, $query, '');
        $this->assertEquals(400, $status);
        $this->assertStringContainsString('presence-', $respBody);
    }

    public function testGetChannelInfo(): void
    {
        $conn = new FakeConnection('1.1', $this->app);
        $this->channels->subscribe($conn, 'my-channel');

        $path = '/apps/10/channels/my-channel';
        $query = $this->signRequest('GET', $path, ['info' => 'subscription_count'], '');

        [$status, , $respBody] = $this->api->handle('GET', $path, $query, '');
        $this->assertEquals(200, $status);

        $decoded = json_decode($respBody, true);
        $this->assertTrue($decoded['occupied']);
        $this->assertEquals(1, $decoded['subscription_count']);
    }

    public function testGetPresenceUsersEndpoint(): void
    {
        $conn = new FakeConnection('1.1', $this->app);
        $this->channels->subscribe($conn, 'presence-board', json_encode(['user_id' => 'user-42']));

        $path = '/apps/10/channels/presence-board/users';
        $query = $this->signRequest('GET', $path, [], '');

        [$status, , $respBody] = $this->api->handle('GET', $path, $query, '');
        $this->assertEquals(200, $status);

        $decoded = json_decode($respBody, true);
        $this->assertCount(1, $decoded['users']);
        $this->assertEquals('user-42', $decoded['users'][0]['id']);

        // Reject users query on non-presence channel
        $badPath = '/apps/10/channels/regular-channel/users';
        $badQuery = $this->signRequest('GET', $badPath, [], '');
        [$badStatus] = $this->api->handle('GET', $badPath, $badQuery, '');
        $this->assertEquals(400, $badStatus);
    }
}
