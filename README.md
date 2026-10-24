# Relay for TinyMVC / Spark

Relay brings realtime events, presence and live updates to TinyMVC / Spark applications. It runs on ReactPHP, uses TinyCore's native HTTP client for backend publishing through `shahinmoyshan/pusher-php-server`, and connects directly to browsers with `pusher-js`.

## Features

| Feature | What it provides |
| --- | --- |
| Public and private channels | Broadcast updates publicly or authorize access through your Spark application |
| Presence channels | Track members with distinct user counts and multiple-tab support |
| Event publishing | Publish single or batched events, target multiple channels, and exclude the originating socket |
| Client events | Exchange events between authorized private/presence subscribers with per-connection rate limits |
| Cache channels | Deliver the latest backend snapshot to new subscribers, with expiry and memory limits |
| Channel queries | Inspect active channels, subscriptions and presence members through signed HTTP requests |
| Multiple applications | Separate credentials, origins, channel state, limits and statistics per app |
| Monitoring | Check daemon health and query signed connection, message and cache statistics |
| Connection management | Heartbeats, bounded buffers, slow-consumer handling and graceful shutdown |
| Spark integration | Relay facade, channel authorization rules, native HTTP transport and start/restart commands |

Relay uses the Pusher Channels protocol, version 7. All browser examples use `pusher-js` directly.

## Installation and registration

```sh
composer require tinymvc/relay
```

PHP 8.2+, JSON and cURL are required. Composer installs the backend Pusher SDK as a runtime dependency, including with `--no-dev`.

Add `Spark\Relay\RelayServiceProvider::class` to the application's existing `bootstrap/providers.php` list. This registers the `Relay` facade service and `php spark relay:start` / `relay:restart` commands.

Create `config/relay.php`:

```php
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
```

Set a unique key and a randomly generated secret; never include the secret in frontend builds. Set `RELAY_ALLOWED_ORIGIN` to the browser's actual origin, including scheme and nonstandard port. For development this might be `http://localhost:5173`. Missing credentials and duplicate app IDs/keys fail configuration loading.

The facade uses the first configured app. For another app, inject an explicitly constructed `new Spark\Relay\Relay($app, $clientConfig)`.

## Broadcasting

```php
use Spark\Relay\Facades\Relay;

Relay::trigger('orders', 'order.created', ['id' => $order->id]);
Relay::trigger('private-chat.42', 'message.sent', ['text' => $message->text], $socketId);
```

Pass an array for structured event data. Strings are JSON-encoded by the SDK in the normal Pusher manner. For already-encoded JSON, use the explicit SDK option: `Relay::client()->trigger($channels, $event, $json, [], true)`.

For asynchronous application work, dispatch a Spark job implementing `Spark\Queue\Contracts\JobInterface` with `Spark\Queue\Dispatchable`, and call Relay in `handle()`. SDK methods ending in `Async` prepare TinyCore HTTP requests; they do not schedule jobs or send requests by themselves. Do not execute synchronous publishing or `HttpPool` inside Relay's event-loop callbacks.

## Private and presence authorization

Your Spark application authenticates users and authorizes channel access; Relay verifies the signed subscriptions. Define channel rules in a provider's `boot()` method, or explicitly load a `routes/channels.php` file from that provider:

```php
use Spark\Relay\ChannelAuth;

ChannelAuth::define('private-chat.{conversationId}', function ($user, $id) {
    return $user && $user->canAccessConversation($id);
});

ChannelAuth::define('presence-workspace.{workspaceId}', function ($user, $id) {
    if (!$user || !$user->belongsToWorkspace($id)) {
        return false;
    }

    return ['id' => (string) $user->id, 'info' => ['name' => $user->name]];
});
```

Patterns match literally except for `{parameter}` placeholders. Callback arguments are positional. Return `true` for private authorization, a presence array for presence authorization, or `false` to deny. Unknown patterns deny access.

Example controller action, registered as a POST `/broadcasting/auth` route under the application's authentication and CSRF middleware:

```php
use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Relay\ChannelAuth;
use Spark\Relay\Facades\Relay;
use Spark\Relay\Protocol;

public function authenticate(Request $request): Response
{
    $user = auth()->user();
    if (!$user) {
        return json(['error' => 'Unauthenticated'], 403);
    }

    $socketId = $request->input('socket_id');
    $channel = $request->input('channel_name');
    if (!is_string($socketId) || !is_string($channel)
        || !Protocol::validSocketId($socketId) || !Protocol::validChannel($channel)) {
        return json(['error' => 'Invalid channel or socket'], 422);
    }

    $authorization = ChannelAuth::authorize($user, $channel);
    if ($authorization === false
        || (Protocol::channelType($channel) === 'presence' && !is_array($authorization))) {
        return json(['error' => 'Forbidden'], 403);
    }

    $presence = is_array($authorization) ? $authorization : null;
    return response(Relay::authorize($channel, $socketId, $presence), 200, [
        'Content-Type' => 'application/json',
    ]);
}
```

Presence IDs must be non-empty strings or integers; zero is valid. Presence is counted by distinct user, with multiple tabs tracked separately. Re-subscribing the same socket cannot change its identity; unsubscribe first. Authorization is checked at subscription time, so application access changes need an explicit disconnection policy or short-lived connections.

## Browser client

```sh
npm install pusher-js
```

```js
import Pusher from 'pusher-js';

const pusher = new Pusher(import.meta.env.VITE_RELAY_APP_KEY, {
    cluster: 'mt1',
    wsHost: window.location.hostname,
    wsPort: 80,
    wssPort: 443,
    forceTLS: window.location.protocol === 'https:',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    channelAuthorization: {
        endpoint: '/broadcasting/auth',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
    },
});

pusher.subscribe('orders').bind('order.created', data => console.log(data));
pusher.subscribe('presence-workspace.42').bind('pusher:subscription_succeeded', members => {
    console.log(members.count);
});
```

This example connects through the same-origin reverse proxy. For direct local development, set `wsHost`, `wsPort: 8080`, and `forceTLS: false` appropriately.

## Cache channels for initial state

Use `cache-`, `private-cache-`, `presence-cache-` or `private-encrypted-cache-` prefixes. Private and presence authorization works exactly as for ordinary channels. Publish full state snapshots when using this feature:

```php
Relay::trigger('private-cache-order.42', 'order.state', ['id' => 42, 'status' => 'shipped']);
```

```js
const order = pusher.subscribe('private-cache-order.42');
order.bind('order.state', renderOrder);
order.bind('pusher:cache_miss', async () => {
    const response = await fetch('/orders/42', { headers: { Accept: 'application/json' } });
    if (response.ok) renderOrder(await response.json());
});
```

Register a matching `ChannelAuth` rule for `private-cache-order.{id}` which checks access to the order. Bind handlers immediately after subscribing. Relay sends subscription success before the cached event, or `pusher:cache_miss` when no value remains. Include a version in application snapshots if a fallback HTTP response can race a newer WebSocket update.

Only backend-published events populate the cache; client events and presence notifications never replace it. Events published with no subscribers are retained, and disconnecting the final subscriber does not delete the cached value. The latest publish replaces the previous value regardless of event name. A socket excluded from the live broadcast can still receive that value on a later subscription.

The cache is isolated per app and defaults to 30-minute expiry, 1,000 channel entries and 10 MiB of encoded frames. Oldest published entries are evicted first when either budget is reached. An individual frame exceeding the byte budget is delivered live and replaces any older cached value with a cache miss. Expired entries are reclaimed on access and by the daemon's five-second maintenance scan. Cache data lives in daemon memory and resets on restart. Channel queries report currently occupied channels.

## Monitoring

`GET /health` responds with `{"status":"ok"}` while the daemon's HTTP/event loop is responsive. It exposes no app identities or counters. Keep this endpoint on the internal monitoring network; a process supervisor remains responsible for recovery.

```php
$stats = Relay::stats(); // Signed GET /apps/{app_id}/stats via TinyCore HTTP.
echo $stats->connections;
echo $stats->messages_sent;

// The native Pusher client can query the same Relay-specific endpoint:
$stats = Relay::client()->get('/stats');
```

Statistics require the app's ordinary HTTP signature and report only that app's current `connections`, `channels`, `subscriptions`, `presence_channels`, `cached_channels` and `cache_bytes`. Connections include sockets with no subscriptions. `connections_total`, `messages_received`, `messages_sent`, `bytes_received` and `bytes_sent` are cumulative since this daemon started; `uptime_seconds` uses a monotonic clock.

Message counters include complete WebSocket text messages and Pusher protocol events. Sent counts mean queued to the transport, not acknowledged delivery; byte counts exclude WebSocket/HTTP/TLS framing. HTTP requests, ping/pong control frames, partial messages and rejected handshakes are not counted. Counters reset on restart. Poll this endpoint from a backend monitoring job, not from browsers; never expose app secrets. External monitoring must retain history and alert on disconnects or publishing failures.

## Limits and failure handling

Limits are bytes unless stated otherwise. Configured limits should match the host's capacity; zero only disables `max_connections` or the memory guard.

| Setting | Default when omitted | Behavior |
| --- | --- | --- |
| Server `max_connections` | 0 | Total established WebSockets; set a finite production limit |
| App `max_connections` | 0 | Established sockets for this app, including unsubscribed sockets |
| `max_pending_connections` | 128 | Bounds HTTP requests and incomplete/rejected handshakes |
| `request_timeout` | 10 seconds | Absolute deadline for headers/body and HTTP response closure |
| `max_header_size` | 16,384 | Rejects oversized HTTP headers with 431 |
| `max_request_size` | 100,000 | Rejects oversized request bodies with 413, before `100 Continue` |
| `max_outbound_buffer_size` | 1,048,576 | Disconnects slow consumers when queued output exceeds the bound |
| App `max_message_size` | 10,000 | Complete inbound WebSocket message, including JSON envelope; checked while buffering |
| App `max_channels_per_connection` | 100 | Bounds each socket's subscriptions |
| App `max_presence_members` | 100 | Distinct users in each presence channel; extra tabs remain allowed |
| App `client_event_rate` | 10/second | Token bucket per socket, with a burst of 10 by default; excess events receive 4301 |
| App `cache_ttl` | 1,800 seconds | Maximum age of a retained cache-channel event |
| App `max_cached_channels` | 1,000 | Maximum retained cache-channel entries, including unoccupied channels |
| App `max_cache_size` | 10,485,760 | Maximum bytes of encoded cached frames per app; entry limit bounds metadata separately |
| `activity_timeout` / `pong_timeout` | 30 / 30 seconds | Heartbeats scanned every five seconds |
| `memory_limit_mb` | 256 MiB | Checks allocated PHP memory every 30 seconds and stops for supervisor restart |

Event data is limited to 10,240 bytes; HTTP events may target at most 100 channels and batches at most 10 events. Increase `max_request_size` if sending batches near these limits, allowing for JSON escaping. Fragmented WebSocket messages are also capped at 1,024 fragments, including empty fragments.

The HTTP API accepts one Content-Length-framed request per connection, then closes it. Chunked request bodies and ambiguous lengths are rejected. The bundled PHP client sends Content-Length. Failed batch validation publishes nothing. Signature timestamps allow at most 600 seconds of skew; synchronize server clocks. Signed URLs may be replayed during that window: publishing is not idempotent.

Client events require `client_events: true`, membership in a private/presence channel, a `client-` prefix and valid size/name. Treat client event contents as untrusted in the receiving application. Debug mode records socket IDs, sizes and close reasons, without event bodies or authorization tokens.

## Deployment

```sh
php spark relay:start --host=127.0.0.1 --port=8080
php spark relay:start --debug
php spark relay:restart
```

Run one daemon for an app's traffic and route both its WebSocket connections and backend HTTP publishing to that daemon. Channel and cache state live in its memory. Persist application records in your database and refresh client state after reconnecting to keep views synchronized across deployments and network interruptions.

Terminate TLS at Nginx or another reverse proxy. Bind the daemon to loopback or a protected network. Expose `/app/` to browsers and keep `/apps/` private for backend publishing. Apply connection/request rate limits at the proxy, including handshake traffic.

Inside an HTTPS virtual host:

```nginx
location /app/ {
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_buffering off;
    proxy_read_timeout 120s;
    proxy_send_timeout 120s;
    proxy_pass http://127.0.0.1:8080;
}
```

Run under a process supervisor with restart enabled, because the memory guard deliberately exits:

```ini
[program:relay]
command=php /var/www/app/spark relay:start
numprocs=1
autostart=true
autorestart=true
user=www-data
stopsignal=TERM
stopwaitsecs=10
redirect_stderr=true
stdout_logfile=/var/www/app/storage/logs/relay.log
```

`SIGTERM`/`SIGINT` close clients with reconnect code 4200, close pending HTTP sockets, and allow two seconds for close frames before forcibly releasing streams. Enable `pcntl` for signal handling. Configure file descriptor limits in the supervisor's global configuration and OS limits, not inside the program section.

`relay:restart` writes an atomic local marker. The daemon checks it every second, closes clients with code 4200 and exits successfully; Supervisor's `autorestart=true` then starts the replacement. Confirm the replacement is running through `/health` and the supervisor after deployment. The marker mechanism works independently of `pcntl`.

By default, CLI-started daemons use `storage_dir() . 'relay.restart'`. Run the restart command as the same OS user as the daemon. Keep the marker outside the public directory and on local storage shared across releases. For symlink-based deployments, explicitly set server `restart_file` to a stable absolute path such as `/var/www/shared/storage/relay.restart` in both old and new releases. Newly started daemons take the existing marker as their baseline, so they do not enter a restart loop. Programmatically constructed `Server` instances must set `restart_file` to enable polling. Use different paths for independently managed daemon groups; one marker deliberately restarts every process watching it.

ReactPHP uses the available event-loop backend. `StreamSelectLoop` has platform-dependent descriptor limits, commonly around 1,024 descriptors for the process. Install an appropriate `uv`, `ev` or `event` extension for higher concurrency, then benchmark on the deployment host to set connection limits. Monitor process restarts, memory, connection counts at the proxy, reconnect rates and publishing failures.

## Verification

```sh
composer install
php tests/run.php
npm install
npm test
npm run test:load
```

The JavaScript runner starts an isolated localhost daemon on a temporary port, times out failures and shuts it down. The suites cover real `pusher-js` connections plus PHP SDK publishing/authentication, presence tabs, whispers, batch/query APIs, cache replay/misses, signed statistics, health checks, deployment restart, raw TCP framing, memory bounds and cleanup. Unit tests also verify cache expiry, eviction, app isolation and authorization before replay. The load smoke sends 50 events to 100 connections and requires all 5,000 deliveries; it is not a sustained production capacity benchmark.

To test a sibling SDK checkout instead of the installed SDK:

```sh
RELAY_PUSHER_SOURCE=../pusher-php-server npm run test:load
```

Release the reviewed SDK and Relay changes together. Applications should deploy their Composer lockfile and verify their own TLS, proxy, authorization rules and workload on staging.

## License

MIT. See [LICENSE](LICENSE).

Protocol references: [Pusher WebSocket protocol](https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/), [Pusher HTTP API](https://pusher.com/docs/channels/library_auth_reference/rest-api/), and [ReactPHP](https://reactphp.org/).
