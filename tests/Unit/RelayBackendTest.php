<?php

namespace Tests\Unit;

use Spark\Relay\App;
use Spark\Relay\Relay;
use Spark\Testing\TestCase;

class RelayBackendTest extends TestCase
{
    private App $app;
    private Relay $relay;

    protected function setUp(): void
    {
        $this->app = new App(
            appId: '100',
            key: 'relay-key',
            secret: 'relay-secret'
        );

        $this->relay = new Relay(
            app: $this->app,
            clientConfig: [
                'host' => '127.0.0.1',
                'port' => 8080,
                'scheme' => 'http',
                'timeout' => 5,
            ]
        );
    }

    public function testClientReturnsConfiguredPusherSdk(): void
    {
        $sdk = $this->relay->client();
        $this->assertInstanceOf(\Pusher\Pusher::class, $sdk);
    }

    public function testAuthorizePrivateChannelReturnsRawJsonString(): void
    {
        $socketId = '1234.5678';
        $channel = 'private-orders.1';

        $authJson = $this->relay->authorize($channel, $socketId);

        // Crucial: Must be a valid JSON string (never double-encoded)
        $this->assertIsString($authJson);
        $decoded = json_decode($authJson, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('auth', $decoded);
        $this->assertStringStartsWith('relay-key:', $decoded['auth']);
    }

    public function testAuthorizePresenceChannelReturnsRawJsonWithChannelData(): void
    {
        $socketId = '1234.5678';
        $channel = 'presence-team';
        $presence = [
            'id' => 'u99',
            'info' => ['role' => 'admin'],
        ];

        $authJson = $this->relay->authorize($channel, $socketId, $presence);

        $this->assertIsString($authJson);
        $decoded = json_decode($authJson, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('auth', $decoded);
        $this->assertArrayHasKey('channel_data', $decoded);

        $channelData = json_decode($decoded['channel_data'], true);
        $this->assertEquals('u99', $channelData['user_id']);
        $this->assertEquals('admin', $channelData['user_info']['role']);
    }
}
