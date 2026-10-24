<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Spark\Relay\{Api, App, ChannelCache, Channels, Metrics, Protocol, RestartSignal};
use Spark\Testing\TestCase;

class FeatureTest extends TestCase
{
    public function testCacheReplaysLatestEventAfterAllSubscribersHaveLeft(): void
    {
        $app = new App('1', 'key', 'secret');
        $channels = new Channels();
        $first = new FakeConnection('1.1', $app);
        $channels->publish($app, 'cache-price', 'price', '{"value":1}');
        $channels->subscribe($first, 'cache-price');
        $this->assertSame('pusher_internal:subscription_succeeded', json_decode($first->sent[0], true)['event']);
        $this->assertSame('{"value":1}', $first->lastSentDecoded()['data']);
        $channels->publish($app, 'cache-price', 'price', '{"value":2}');
        $channels->disconnect($first);
        $this->assertFalse($channels->occupied('1', 'cache-price'));
        $next = new FakeConnection('2.2', $app);
        $channels->subscribe($next, 'cache-price');
        $this->assertCount(2, $next->sent);
        $this->assertSame('{"value":2}', $next->lastSentDecoded()['data']);
    }

    public function testCacheExpiryAndAppIsolationProduceCacheMiss(): void
    {
        $time = 100.0;
        $cache = new ChannelCache(static function () use (&$time) {
            return $time; });
        $channels = new Channels($cache);
        $app = new App('1', 'key', 'secret', cacheTtl: 10);
        $channels->publish($app, 'cache-room', 'state', '{}');
        $other = new FakeConnection('1.1', new App('2', 'other', 'other-secret'));
        $channels->subscribe($other, 'cache-room');
        $this->assertSame('pusher:cache_miss', $other->lastSentDecoded()['event']);
        $time = 110.0;
        $expired = new FakeConnection('2.2', $app);
        $channels->subscribe($expired, 'cache-room');
        $this->assertSame('pusher:cache_miss', $expired->lastSentDecoded()['event']);
        $this->assertSame(['cached_channels' => 0, 'cache_bytes' => 0], $cache->statistics('1'));
    }

    public function testCacheEvictionHonorsBothEntryAndByteBudgets(): void
    {
        $cache = new ChannelCache();
        $app = new App('1', 'key', 'secret', maxCachedChannels: 2, maxCacheSize: 8);
        $cache->put($app, 'cache-a', 'aaa');
        $cache->put($app, 'cache-b', 'bbb');
        $cache->put($app, 'cache-a', 'new'); // Refresh makes a the newest entry.
        $cache->put($app, 'cache-c', 'ccc');
        $this->assertNull($cache->get('1', 'cache-b'));
        $this->assertSame('new', $cache->get('1', 'cache-a'));
        $cache->put($app, 'cache-d', '123456');
        $this->assertNull($cache->get('1', 'cache-a'));
        $this->assertNull($cache->get('1', 'cache-c'));
        $this->assertSame(['cached_channels' => 1, 'cache_bytes' => 6], $cache->statistics('1'));
        $cache->put($app, 'cache-d', str_repeat('x', 9));
        $this->assertNull($cache->get('1', 'cache-d')); // Never replay a stale, replaced value.
        $this->assertSame(0, $cache->statistics('1')['cache_bytes']);
    }

    public function testPruningReleasesExpiredAppsWithoutNewTraffic(): void
    {
        $time = 0.0;
        $cache = new ChannelCache(static function () use (&$time) {
            return $time; });
        $cache->put(new App('1', 'key', 'secret', cacheTtl: 1), 'cache-a', 'payload');
        $cache->put(new App('2', 'key2', 'secret2', cacheTtl: 3), 'cache-a', 'other');
        $time = 2.0;
        $cache->prune();
        $this->assertSame(0, $cache->statistics('1')['cached_channels']);
        $this->assertSame('other', $cache->get('2', 'cache-a'));
    }

    public function testAllCacheChannelTypesAndOrdinaryChannels(): void
    {
        $app = new App('1', 'key', 'secret');
        $channels = new Channels();
        foreach (['cache-room', 'private-cache-room', 'presence-cache-room', 'private-encrypted-cache-room'] as $name) {
            $this->assertTrue(Protocol::isCacheChannel($name));
            $connection = new FakeConnection($name, $app);
            $channels->publish($app, $name, 'state', '{"secret":1}');
            $channels->subscribe($connection, $name, '{"user_id":"1"}');
            $this->assertSame('state', $connection->lastSentDecoded()['event']);
        }
        $ordinary = new FakeConnection('3.3', $app);
        $channels->publish($app, 'private-room', 'state', '{}');
        $channels->subscribe($ordinary, 'private-room');
        $this->assertCount(1, $ordinary->sent);
        $this->assertSame(4, $channels->statistics('1')['cached_channels']);
    }

    public function testWhispersAndPresenceEventsNeverReplaceCachedBackendState(): void
    {
        $app = new App('1', 'key', 'secret', clientEvents: true);
        $channels = new Channels();
        $first = new FakeConnection('1.1', $app);
        $channels->publish($app, 'presence-cache-room', 'state', '{"version":1}');
        $channels->subscribe($first, 'presence-cache-room', '{"user_id":"1"}');
        $this->assertTrue($channels->whisper($first, 'presence-cache-room', 'client-typing', '{}'));
        $next = new FakeConnection('2.2', $app);
        $channels->subscribe($next, 'presence-cache-room', '{"user_id":"2"}');
        $this->assertSame('state', $next->lastSentDecoded()['event']);
        $this->assertSame('{"version":1}', $next->lastSentDecoded()['data']);
    }

    public function testStatsRequireSignatureAndNeverCrossApplicationBoundaries(): void
    {
        $app = new App('1', 'key', 'secret');
        $other = new App('2', 'other', 'other-secret');
        $channels = new Channels();
        $metrics = new Metrics();
        $metrics->connected('1');
        $metrics->connected('2');
        $metrics->connected('2');
        $metrics->received('1', 10);
        $metrics->sent('1', 20);
        $metrics->received('2', 999);
        $channels->subscribe(new FakeConnection('1.1', $app), 'presence-room', '{"user_id":"u"}');
        $channels->subscribe(new FakeConnection('1.1', $other), 'private-hidden');
        $api = new Api($channels, ['1' => $app, '2' => $other], $metrics);
        $path = '/apps/1/stats';
        $this->assertSame(401, $api->handle('GET', $path, [], '')[0]);
        $query = Pusher::build_auth_query_params('key', 'secret', 'GET', $path);
        [$status, $headers, $body] = $api->handle('GET', $path, $query, '');
        $data = json_decode($body, true);
        $this->assertSame(200, $status);
        $this->assertSame('no-store', $headers['Cache-Control']);
        foreach (['connections', 'connections_total', 'messages_received', 'messages_sent', 'channels', 'subscriptions', 'presence_channels'] as $key) {
            $this->assertSame(1, $data[$key]);
        }
        $this->assertSame(10, $data['bytes_received']);
        $this->assertSame(20, $data['bytes_sent']);
        $metrics->disconnected('1');
        $metrics->disconnected('1');
        $this->assertSame(0, $metrics->snapshot('1')['connections']);
        $this->assertSame(1, $metrics->snapshot('1')['connections_total']);
        $this->assertSame(2, $metrics->snapshot('2')['connections']);
        $this->assertSame(0, $metrics->snapshot('unknown')['connections']);
    }

    public function testRestartMarkerChangesEvenWithinOneSecondAndNewWorkersIgnoreOldRequests(): void
    {
        $file = sys_get_temp_dir() . '/relay-restart-test-' . bin2hex(random_bytes(8));
        try {
            $oldWorker = new RestartSignal($file);
            $this->assertFalse($oldWorker->requested());
            $oldWorker->request();
            $this->assertTrue($oldWorker->requested());
            $newWorker = new RestartSignal($file);
            $this->assertFalse($newWorker->requested());
            $newWorker->request();
            $this->assertTrue($newWorker->requested());
            $this->assertFalse((new RestartSignal($file))->requested());
        } finally {
            @unlink($file);
        }
    }

    public function testRestartMarkerMustBeALocalAbsolutePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RestartSignal('https://example.test/restart');
    }

    public function testMalformedRestartMarkerIsRejected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'relay-restart-test-');
        try {
            file_put_contents($file, 'not a valid marker');
            $this->expectException(\RuntimeException::class);
            new RestartSignal($file);
        } finally {
            unlink($file);
        }
    }
}
