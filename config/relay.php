<?php

return [
    'server' => [
        'host' => env('RELAY_SERVER_HOST', '0.0.0.0'),
        'port' => (int) env('RELAY_SERVER_PORT', 8080),
        'activity_timeout' => (int) env('RELAY_ACTIVITY_TIMEOUT', 30), // idle seconds before server sends pusher:ping
        'pong_timeout' => (int) env('RELAY_PONG_TIMEOUT', 30),         // seconds waiting for pong before close 4201
        'max_connections' => (int) env('RELAY_MAX_CONNECTIONS', 0),   // 0 = unlimited
        'max_request_size' => (int) env('RELAY_MAX_REQUEST_SIZE', 100000), // HTTP API body limit (bytes)
        'memory_limit_mb' => (int) env('RELAY_MEMORY_LIMIT_MB', 256), // restart when memory exceeds this (MB)
    ],
    'apps' => [
        [
            'app_id' => env('RELAY_APP_ID', '1'),
            'key' => env('RELAY_APP_KEY', 'relay-key'),
            'secret' => env('RELAY_APP_SECRET', 'relay-secret'),
            'allowed_origins' => ['*'],
            'client_events' => (bool) env('RELAY_CLIENT_EVENTS', false),
            'max_message_size' => (int) env('RELAY_MAX_MESSAGE_SIZE', 10000), // inbound WS payload cap (bytes)
        ],
    ],
    'client' => [
        'host' => env('RELAY_HOST', '127.0.0.1'),
        'port' => (int) env('RELAY_PORT', 8080),
        'scheme' => env('RELAY_SCHEME', 'http'),
    ],
];
