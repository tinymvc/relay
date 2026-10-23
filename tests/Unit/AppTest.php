<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Spark\Relay\App;
use Spark\Testing\TestCase;

class AppTest extends TestCase
{
    public function testAppFailsFastWithoutRequiredFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new App(appId: '', key: 'k', secret: 's');
    }

    public function testAppFailsFastWithoutKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new App(appId: '1', key: '', secret: 's');
    }

    public function testAppFailsFastWithoutSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new App(appId: '1', key: 'k', secret: '');
    }

    public function testAppInstantiationAndDefaults(): void
    {
        $app = new App(appId: '1', key: 'key1', secret: 'sec1');
        $this->assertEquals('1', $app->appId);
        $this->assertEquals('key1', $app->key);
        $this->assertEquals('sec1', $app->secret);
        $this->assertEquals(['*'], $app->allowedOrigins);
        $this->assertFalse($app->clientEvents);
        $this->assertEquals(10000, $app->maxMessageSize);
        $this->assertEquals(0, $app->maxConnections);
    }

    public function testAppFromConfigAndLookups(): void
    {
        $config = [
            [
                'app_id' => 'app-1',
                'key' => 'key-1',
                'secret' => 'sec-1',
                'allowed_origins' => ['http://localhost:3000'],
                'client_events' => true,
                'max_message_size' => 5000,
            ],
            [
                'app_id' => 'app-2',
                'key' => 'key-2',
                'secret' => 'sec-2',
            ],
        ];

        $apps = App::loadAll($config);
        $this->assertCount(2, $apps);

        $app1 = App::byKey('key-1', $apps);
        $this->assertNotNull($app1);
        $this->assertEquals('app-1', $app1->appId);
        $this->assertTrue($app1->clientEvents);
        $this->assertEquals(['http://localhost:3000'], $app1->allowedOrigins);

        $app2 = App::byId('app-2', $apps);
        $this->assertNotNull($app2);
        $this->assertEquals('key-2', $app2->key);

        $this->assertNull(App::byKey('missing', $apps));
        $this->assertNull(App::byId('missing', $apps));
    }
}
