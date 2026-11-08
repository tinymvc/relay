<?php

return [
    'server' => [
        'host' => env('RELAY_SERVER_HOST', '127.0.0.1'),
        'port' => (int) env('RELAY_SERVER_PORT', 8080),
        'activity_timeout' => 30,
        'pong_timeout' => 30,
        'max_connections' => 500,
        'max_pending_connections' => 128,
        'request_timeout' => 10,
        'max_header_size' => 16384,
        'max_request_size' => 100000,
        'max_outbound_buffer_size' => 1048576,
        'memory_limit_mb' => 256,
    ],
    'apps' => [
        [
            'app_id' => env('RELAY_APP_ID', '1'),
            'key' => env('RELAY_APP_KEY'),
            'secret' => env('RELAY_APP_SECRET'),
            'allowed_origins' => [env('RELAY_ALLOWED_ORIGIN', 'https://example.com')],
            'client_events' => false,
            'max_message_size' => 16384,
            'max_connections' => 500,
            'max_channels_per_connection' => 100,
            'max_presence_members' => 100,
            'client_event_rate' => 10,
            'cache_ttl' => 1800,
            'max_cached_channels' => 1000,
            'max_cache_size' => 10485760,
        ],
    ],
    'client' => [
        'host' => env('RELAY_HOST', '127.0.0.1'),
        'port' => (int) env('RELAY_PORT', 8080),
        'scheme' => env('RELAY_SCHEME', 'http'),
        'timeout' => 5,
    ],
];