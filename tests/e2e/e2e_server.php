<?php

require __DIR__ . '/../../vendor/autoload.php';

use React\EventLoop\Loop;
use Spark\Relay\Api;
use Spark\Relay\App;
use Spark\Relay\Channels;
use Spark\Relay\Server;

$app = new App(
    appId: '10',
    key: 'e2e-key',
    secret: 'e2e-secret',
    allowedOrigins: ['*'],
    clientEvents: true,
    maxMessageSize: 10000
);

$channels = new Channels();
$apps = [$app->appId => $app];
$api = new Api($channels, $apps);
$loop = Loop::get();

$server = new Server(
    channels: $channels,
    apps: $apps,
    api: $api,
    config: [
        'host' => '127.0.0.1',
        'port' => (int) (getenv('RELAY_TEST_PORT') ?: 8088),
        'activity_timeout' => 3,
        'pong_timeout' => 3,
        'max_connections' => 150,
        'request_timeout' => 1,
        'memory_limit_mb' => 256,
        'restart_file' => getenv('RELAY_TEST_RESTART_FILE') ?: null,
    ],
    loop: $loop,
    debug: false
);

$server->start();
echo "E2E Server listening on http://127.0.0.1:8088" . PHP_EOL;

$loop->run();
