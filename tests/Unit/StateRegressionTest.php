<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Spark\Foundation\Application;
use Spark\Relay\{Api, App, ChannelAuth, Channels, Protocol, Relay, RelayServiceProvider, Signature};
use Spark\Testing\TestCase;

class StateRegressionTest extends TestCase
{
    public function testInvalidPresenceNeverCreatesMembership(): void
    {
        $app = new App('1', 'key', 'secret');
        $channels = new Channels();
        $connection = new FakeConnection('1.1', $app);
        foreach ([null, '{}', 'null', '{"user_id":[]}', '{"user_id":""}', '{"user_id":true}'] as $data) {
            $this->assertFalse($channels->subscribe($connection, 'presence-room', $data));
            $this->assertFalse($channels->occupied('1', 'presence-room'));
            $this->assertSame(0, $channels->connectionCount('1'));
        }
        $this->assertCount(0, $connection->sent);
    }

    public function testResubscribeCannotChangePresenceIdentityOrLeaveGhostUsers(): void
    {
        $app = new App('1', 'key', 'secret');
        $channels = new Channels();
        $connection = new FakeConnection('1.1', $app);
        $channels->subscribe($connection, 'presence-room', '{"user_id":"1"}');
        $this->assertFalse($channels->subscribe($connection, 'presence-room', '{"user_id":"2"}'));
        $this->assertSame(['users' => [['id' => '1']]], $channels->users('1', 'presence-room'));
        $channels->disconnect($connection);
        $this->assertNull($channels->users('1', 'presence-room'));
    }

    public function testNumericPresenceIdsAreStringsAndZeroIsValid(): void
    {
        $app = new App('1', 'key', 'secret');
        $connection = new FakeConnection('1.1', $app);
        $channels = new Channels();
        $this->assertTrue($channels->subscribe($connection, 'presence-room', '{"user_id":0}'));
        $data = json_decode($connection->lastSentDecoded()['data'], true);
        $this->assertSame(['0'], $data['presence']['ids']);
    }

    public function testChannelAndPresenceLimitsDoNotLeakState(): void
    {
        $app = new App('1', 'key', 'secret', maxChannelsPerConnection: 1, maxPresenceMembers: 1);
        $channels = new Channels();
        $first = new FakeConnection('1.1', $app);
        $second = new FakeConnection('2.2', $app);
        $this->assertTrue($channels->subscribe($first, 'presence-room', '{"user_id":"1"}'));
        $this->assertFalse($channels->subscribe($first, 'other'));
        $this->assertFalse($channels->subscribe($second, 'presence-room', '{"user_id":"2"}'));
        $this->assertTrue($channels->subscribe($second, 'presence-room', '{"user_id":"1"}'));
        $this->assertSame(2, $channels->channelInfo('1', 'presence-room', ['subscription_count'])['subscription_count']);
    }

    public function testIdenticalChannelsAndSocketIdsRemainAppIsolated(): void
    {
        $firstApp = new App('1', 'k1', 's1');
        $secondApp = new App('2', 'k2', 's2');
        $channels = new Channels();
        $first = new FakeConnection('1.1', $firstApp);
        $second = new FakeConnection('1.1', $secondApp);
        $channels->subscribe($first, 'news');
        $channels->subscribe($second, 'news');
        $channels->publish($firstApp, ['news', 'news'], 'test', '{}');
        $this->assertCount(2, $first->sent);
        $this->assertCount(1, $second->sent);
        $channels->disconnect($first);
        $this->assertTrue($channels->occupied('2', 'news'));
    }

    public function testAuthorizationPatternIsLiteralAndArgumentsArePositional(): void
    {
        ChannelAuth::clear();
        try {
            ChannelAuth::define('private-room.{roomId}', fn($user, $id) => $id === '42');
            $this->assertTrue(ChannelAuth::authorize(null, 'private-room.42'));
            $this->assertFalse(ChannelAuth::authorize(null, "private-room.42\n"));
            ChannelAuth::clear();
            ChannelAuth::define('private-room(foo)', fn() => true);
            $this->assertFalse(ChannelAuth::authorize(null, 'private-roomfoo'));
            ChannelAuth::clear();
            ChannelAuth::define('private-room.{id}', fn() => 'yes');
            $this->assertFalse(ChannelAuth::authorize(null, 'private-room.42'));
        } finally {
            ChannelAuth::clear();
        }
    }

    public function testSignedQueryValuesAreDecodedOnlyOnce(): void
    {
        $app = new App('1', 'key', 'secret');
        $params = Pusher::build_auth_query_params('key', 'secret', 'GET', '/apps/1/channels', ['filter_by_prefix' => 'literal%2B+ space']);
        $this->assertNull(Signature::verifyApiRequest($app, 'GET', '/apps/1/channels', $params));
        $this->assertNull(Signature::verifyApiRequest($app, 'GET', '/apps/1/channels', http_build_query($params)));
    }

    public function testAmbiguousAndStructuredQueryParametersAreRejected(): void
    {
        foreach (['auth_key=a&AUTH_KEY=b', 'info[]=x', 'auth.key=a', 'auth_key=x&auth_key=y'] as $query) {
            $this->assertNull(Signature::parseQuery($query));
        }
        $this->assertNull(Signature::parseQuery(['info' => [['nested']]]));
    }

    public function testAuthVersionAndIntegerTimestampAreRequired(): void
    {
        $app = new App('1', 'key', 'secret');
        $params = Pusher::build_auth_query_params('key', 'secret', 'GET', '/apps/1/channels', [], '2.0');
        $this->assertSame('Unsupported auth_version', Signature::verifyApiRequest($app, 'GET', '/apps/1/channels', $params));
        $params['auth_timestamp'] = time() . '.5';
        $this->assertSame('Missing or invalid auth_timestamp', Signature::verifyApiRequest($app, 'GET', '/apps/1/channels', $params));
    }

    public function testEncodedChannelPathMatchesSdkAndUppercaseFiltersAreApplied(): void
    {
        $app = new App('1', 'key', 'secret');
        $channels = new Channels();
        $channels->subscribe(new FakeConnection('1.1', $app), 'private-a@b');
        $api = new Api($channels, ['1' => $app]);
        $path = '/apps/1/channels/private-a%40b';
        $query = Pusher::build_auth_query_params('key', 'secret', 'GET', $path);
        [$status, , $body] = $api->handle('GET', $path, $query, '');
        $this->assertSame(200, $status);
        $this->assertTrue(json_decode($body, true)['occupied']);
        $path = '/apps/1/channels';
        $query = Pusher::build_auth_query_params('key', 'secret', 'GET', $path, ['filter_by_prefix' => 'other']);
        $query['FILTER_BY_PREFIX'] = $query['filter_by_prefix'];
        unset($query['filter_by_prefix']);
        [, , $body] = $api->handle('GET', $path, $query, '');
        $this->assertSame('{"channels":{}}', $body);
    }

    public function testAmbiguousBatchCannotPublishAnUnvalidatedChannel(): void
    {
        $app = new App('1', 'key', 'secret');
        $channels = new Channels();
        $connection = new FakeConnection('1.1', $app);
        $channels->subscribe($connection, 'news');
        $body = json_encode(['batch' => [['name' => 'test', 'channel' => 'news', 'channels' => ['different'], 'data' => '{}']]]);
        $path = '/apps/1/batch_events';
        $query = Pusher::build_auth_query_params('key', 'secret', 'POST', $path, ['body_md5' => md5($body)]);
        [$status] = (new Api($channels, ['1' => $app]))->handle('POST', $path, $query, $body);
        $this->assertSame(400, $status);
        $this->assertCount(1, $connection->sent);
    }

    public function testMalformedEventDataReturns400WithoutWarnings(): void
    {
        $app = new App('1', 'key', 'secret');
        $api = new Api(new Channels(), ['1' => $app]);
        foreach ([['data' => []], ['channels' => [[]]], ['name' => []]] as $invalid) {
            $body = json_encode(array_replace(['name' => 'test', 'channels' => ['news'], 'data' => '{}'], $invalid));
            $path = '/apps/1/events';
            $query = Pusher::build_auth_query_params('key', 'secret', 'POST', $path, ['body_md5' => md5($body)]);
            $this->assertSame(400, $api->handle('POST', $path, $query, $body)[0]);
        }
    }

    public function testDuplicateAppKeysFailFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::loadAll([['app_id' => '1', 'key' => 'same', 'secret' => 'a'], ['app_id' => '2', 'key' => 'same', 'secret' => 'b']]);
    }

    public function testPresenceAuthorizationRequiresAnIdentity(): void
    {
        $relay = new Relay(new App('1', 'key', 'secret'), []);
        $this->expectException(\InvalidArgumentException::class);
        $relay->authorize('presence-room', '1.1');
    }

    public function testProviderResolvesRelayWithoutAutowiringAppCredentials(): void
    {
        $previous = Application::$app;
        try {
            $app = new Application(dirname(__DIR__, 2));
            $app->instance(\Spark\Console\Commands::class, new \Spark\Console\Commands());
            $app->alias('commands', \Spark\Console\Commands::class);
            $provider = new RelayServiceProvider();
            $provider->register();
            $provider->boot();
            $this->assertInstanceOf(Relay::class, $app->get(Relay::class));
            $this->assertSame($app->get(Relay::class), $app->get('relay'));
            $this->assertTrue($app->get('commands')->hasCommand('relay:start'));
            $this->assertTrue($app->get('commands')->hasCommand('relay:restart'));
        } finally {
            Application::$app = $previous;
        }
    }
}
