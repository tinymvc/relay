<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Spark\Relay\App;
use Spark\Relay\Signature;
use Spark\Testing\TestCase;

class SignatureTest extends TestCase
{
    private App $app;
    private Pusher $sdk;

    protected function setUp(): void
    {
        $this->app = new App(
            appId: '100',
            key: 'testkey123',
            secret: 'testsecret456'
        );

        $this->sdk = new Pusher(
            $this->app->key,
            $this->app->secret,
            $this->app->appId
        );
    }

    public function testPrivateChannelAuthMatchesRealSdk(): void
    {
        $socketId = '1234.5678';
        $channel = 'private-chat.42';

        $relayAuth = Signature::channelAuth($this->app, $socketId, $channel);

        // SDK returns JSON: {"auth":"key:signature"}
        $sdkJson = $this->sdk->authorizeChannel($channel, $socketId);
        $sdkData = json_decode($sdkJson, true);

        $this->assertEquals($sdkData['auth'], $relayAuth);
        $this->assertTrue(Signature::verifyChannelAuth($this->app, $relayAuth, $socketId, $channel));
    }

    public function testPresenceChannelAuthMatchesRealSdk(): void
    {
        $socketId = '9876.5432';
        $channel = 'presence-room.99';
        $channelData = json_encode(['user_id' => 'u123', 'user_info' => ['name' => 'Alice']]);

        $relayAuth = Signature::channelAuth($this->app, $socketId, $channel, $channelData);

        // SDK returns JSON: {"auth":"key:signature","channel_data":"..."}
        $sdkJson = $this->sdk->authorizeChannel($channel, $socketId, $channelData);
        $sdkData = json_decode($sdkJson, true);

        $this->assertEquals($sdkData['auth'], $relayAuth);
        $this->assertTrue(Signature::verifyChannelAuth($this->app, $relayAuth, $socketId, $channel, $channelData));
    }

    public function testTamperedChannelAuthRejected(): void
    {
        $socketId = '1234.5678';
        $channel = 'private-chat.1';
        $auth = Signature::channelAuth($this->app, $socketId, $channel);

        // Tampered channel
        $this->assertFalse(Signature::verifyChannelAuth($this->app, $auth, $socketId, 'private-chat.2'));
        // Tampered socket id
        $this->assertFalse(Signature::verifyChannelAuth($this->app, $auth, '9999.8888', $channel));
        // Tampered presence data
        $this->assertFalse(Signature::verifyChannelAuth($this->app, $auth, $socketId, $channel, '{"user_id":"bad"}'));
    }

    public function testVerifyApiRequestMatchesSdkSigningHelper(): void
    {
        $now = time();
        $path = '/apps/100/events';
        $body = json_encode(['name' => 'my-event', 'channels' => ['test-channel'], 'data' => 'hello']);

        // Generate params using the official SDK helper
        $queryParams = Pusher::build_auth_query_params(
            $this->app->key,
            $this->app->secret,
            'POST',
            $path,
            ['body_md5' => md5($body)],
            '1.0',
            $now
        );

        $result = Signature::verifyApiRequest(
            $this->app,
            'POST',
            $path,
            $queryParams,
            $body,
            $now
        );

        $this->assertNull($result, 'Valid SDK signature should return null');
    }

    public function testVerifyApiRequestRejectsBadMd5(): void
    {
        $now = time();
        $path = '/apps/100/events';
        $body = 'actual body';

        $queryParams = Pusher::build_auth_query_params(
            $this->app->key,
            $this->app->secret,
            'POST',
            $path,
            ['body_md5' => md5('different body')],
            '1.0',
            $now
        );

        $result = Signature::verifyApiRequest($this->app, 'POST', $path, $queryParams, $body, $now);
        $this->assertEquals('Invalid body_md5', $result);
    }

    public function testVerifyApiRequestRejectsSkewedTimestamp(): void
    {
        $now = time();
        $expiredTime = $now - 700; // > 600s
        $path = '/apps/100/channels';

        $queryParams = Pusher::build_auth_query_params(
            $this->app->key,
            $this->app->secret,
            'GET',
            $path,
            [],
            '1.0',
            $expiredTime
        );

        $result = Signature::verifyApiRequest($this->app, 'GET', $path, $queryParams, '', $now);
        $this->assertEquals('Timestamp expired or skewed', $result);
    }

    public function testVerifyApiRequestRejectsWrongKey(): void
    {
        $now = time();
        $path = '/apps/100/channels';

        $queryParams = Pusher::build_auth_query_params(
            'wrong-key',
            $this->app->secret,
            'GET',
            $path,
            [],
            '1.0',
            $now
        );

        $result = Signature::verifyApiRequest($this->app, 'GET', $path, $queryParams, '', $now);
        $this->assertEquals('Invalid auth_key', $result);
    }

    public function testVerifyApiRequestHandlesUppercaseAndUnsortedParams(): void
    {
        $now = time();
        $path = '/apps/100/channels';

        $queryParams = Pusher::build_auth_query_params(
            $this->app->key,
            $this->app->secret,
            'GET',
            $path,
            ['filter_by_prefix' => 'presence-', 'info' => 'user_count'],
            '1.0',
            $now
        );

        // Intentionally scramble order and change case of keys in the incoming array
        $scrambled = [
            'INFO' => 'user_count',
            'auth_signature' => $queryParams['auth_signature'],
            'FILTER_BY_PREFIX' => 'presence-',
            'auth_timestamp' => (string) $now,
            'auth_key' => $this->app->key,
            'auth_version' => '1.0',
        ];

        $result = Signature::verifyApiRequest($this->app, 'GET', $path, $scrambled, '', $now);
        $this->assertNull($result);
    }
}
