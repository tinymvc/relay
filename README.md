# Relay for TinyMVC / Spark

Realtime events, presence and live updates for TinyMVC / Spark applications.

Relay is a Pusher-compatible WebSocket server built on **ReactPHP**. Your backend publishes events through `shahinmoyshan/pusher-php-server` using TinyCore's native HTTP client, and browsers connect directly with **`pusher-js`**.

> Relay implements the **Pusher Channels protocol, version 7**. Every browser example in this document uses `pusher-js` directly.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick Start](#quick-start)
- [Broadcasting Events](#broadcasting-events)
- [Private and Presence Channels](#private-and-presence-channels)
- [Browser Client](#browser-client)
- [Cache Channels](#cache-channels)
- [Monitoring](#monitoring)
- [Limits and Failure Handling](#limits-and-failure-handling)
- [Deployment](#deployment)
- [Testing](#testing)
- [License](#license)

---

## Features

| Feature | What it provides |
| --- | --- |
| **Public and private channels** | Broadcast publicly, or authorize access through your Spark application |
| **Presence channels** | Track members by distinct user, with multi-tab support |
| **Event publishing** | Single or batched events, multiple target channels, and exclusion of the originating socket |
| **Client events** | Events between authorized private/presence subscribers, with per-connection rate limits |
| **Cache channels** | Deliver the latest backend snapshot to new subscribers, with expiry and memory limits |
| **Channel queries** | Inspect active channels, subscriptions and presence members via signed HTTP requests |
| **Multiple applications** | Separate credentials, origins, channel state, limits and statistics per app |
| **Monitoring** | Daemon health check plus signed connection, message and cache statistics |
| **Connection management** | Heartbeats, bounded buffers, slow-consumer handling and graceful shutdown |
| **Spark integration** | `Relay` facade, channel authorization rules, native HTTP transport and start/restart commands |

---

## Requirements

- PHP **8.2+**
- PHP extensions: `json`, `curl`
- Optional: `pcntl` (signal handling), and `uv` / `ev` / `event` (higher concurrency)

---

## Installation

```sh
composer require tinymvc/relay
```

The backend Pusher SDK is installed automatically as a runtime dependency, including with `--no-dev`.

Publish the config file into your project:

```sh
php spark vendor:publish --tag=relay-config
```

---

## Configuration

After publishing, edit the relay config and set:

| Setting | Notes |
| --- | --- |
| **App key** | A unique key. Safe to expose to the browser. |
| **App secret** | A randomly generated secret. **Never include it in frontend builds.** |
| `RELAY_ALLOWED_ORIGIN` | The browser's actual origin, including scheme and any nonstandard port (e.g. `http://localhost:5173` in development). |

Configuration loading fails if credentials are missing or if app IDs/keys are duplicated.

The `Relay` facade always uses the **first configured app**. To use a different one, construct it explicitly:

```php
$relay = new Spark\Relay\Relay($app, $clientConfig);
```

---

## Quick Start

**1. Start the daemon**

```sh
php spark relay:start --host=127.0.0.1 --port=8080
```

**2. Publish an event from your backend**

```php
use Spark\Relay\Facades\Relay;

Relay::trigger('orders', 'order.created', ['id' => $order->id]);
```

**3. Listen in the browser**

```js
import Pusher from 'pusher-js';

const pusher = new Pusher(import.meta.env.VITE_RELAY_APP_KEY, {
    cluster: 'mt1',
    wsHost: window.location.hostname,
    wsPort: 8080,
    forceTLS: false,
    enabledTransports: ['ws'],
    disableStats: true,
});

pusher.subscribe('orders').bind('order.created', data => console.log(data));
```

> This is the direct local-development setup. For production, see [Browser Client](#browser-client) and [Deployment](#deployment).

---

## Broadcasting Events

```php
use Spark\Relay\Facades\Relay;

// Public channel
Relay::trigger('orders', 'order.created', ['id' => $order->id]);

// Private channel, excluding the originating socket
Relay::trigger('private-chat.42', 'message.sent', ['text' => $message->text], $socketId);
```

**Payloads**

- Pass an **array** for structured event data.
- Strings are JSON-encoded by the SDK in the normal Pusher manner.
- For already-encoded JSON, use the explicit SDK option:

```php
Relay::client()->trigger($channels, $event, $json, [], true);
```

**Asynchronous work**

To publish outside the request cycle, dispatch a Spark job that implements `Spark\Queue\Contracts\JobInterface` and uses `Spark\Queue\Dispatchable`, then call Relay inside `handle()`.

> **Note**
> SDK methods ending in `Async` only *prepare* TinyCore HTTP requests; they do not schedule jobs or send anything themselves.
> Never run synchronous publishing or `HttpPool` inside Relay's event-loop callbacks.

---

## Private and Presence Channels

Your Spark application authenticates users and authorizes channel access. Relay verifies the signed subscriptions.

### 1. Define channel rules

Define rules in a provider's `boot()` method, or load a `routes/channels.php` file explicitly from that provider.

```php
use Spark\Relay\ChannelAuth;

// Private channel: return true to allow, false to deny
ChannelAuth::define('private-chat.{conversationId}', function ($user, $id) {
    return $user && $user->canAccessConversation($id);
});

// Presence channel: return member data to allow, false to deny
ChannelAuth::define('presence-workspace.{workspaceId}', function ($user, $id) {
    if (!$user || !$user->belongsToWorkspace($id)) {
        return false;
    }

    return ['id' => (string) $user->id, 'info' => ['name' => $user->name]];
});
```

**Rule behavior**

- Patterns match literally, except for `{parameter}` placeholders.
- Callback arguments are positional.
- Return `true` for private authorization, a presence array for presence authorization, or `false` to deny.
- **Unknown patterns are denied.**

### 2. Add the authorization endpoint

Register a `POST /broadcasting/auth` route under your application's authentication and CSRF middleware:

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

### Presence rules

- Presence IDs must be non-empty strings or integers (`0` is valid).
- Members are counted by **distinct user**; multiple tabs are tracked separately.
- Re-subscribing the same socket cannot change its identity. Unsubscribe first.

> **Warning**
> Authorization is checked **at subscription time only**. If a user's access changes, you need an explicit disconnection policy or short-lived connections.

### Client events

Client events (whispers) require all of the following:

- `client_events: true` in the app config
- Membership in a private or presence channel
- An event name with the `client-` prefix, and valid name/size

Treat client event contents as **untrusted** in the receiving application. Excess events per socket receive error code `4301`.

---

## Browser Client

```sh
npm install pusher-js
```

Production setup behind a same-origin reverse proxy:

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

For direct local development (no proxy), set `wsHost`, `wsPort: 8080` and `forceTLS: false` accordingly.

---

## Cache Channels

Cache channels deliver the **latest backend-published event** to each new subscriber, so clients get initial state without a separate request.

Use one of these prefixes:

`cache-` · `private-cache-` · `presence-cache-` · `private-encrypted-cache-`

Private and presence authorization works exactly as it does for ordinary channels. Publish **full state snapshots** when using this feature.

**Backend**

```php
Relay::trigger('private-cache-order.42', 'order.state', ['id' => 42, 'status' => 'shipped']);
```

Register a matching rule, such as `private-cache-order.{id}`, that checks access to the order.

**Browser**

```js
const order = pusher.subscribe('private-cache-order.42');
order.bind('order.state', renderOrder);
order.bind('pusher:cache_miss', async () => {
    const response = await fetch('/orders/42', { headers: { Accept: 'application/json' } });
    if (response.ok) renderOrder(await response.json());
});
```

Bind handlers immediately after subscribing. Relay sends subscription success first, then either the cached event or `pusher:cache_miss`.

> **Tip**
> Include a version in your snapshots if the fallback HTTP response could race a newer WebSocket update.

### Cache behavior

- Only **backend-published** events populate the cache. Client events and presence notifications never replace it.
- The **latest publish replaces** the previous value, regardless of event name.
- Events published with no subscribers are retained, and disconnecting the last subscriber does not delete the cached value.
- A socket excluded from the live broadcast can still receive the value on a later subscription.
- A single frame larger than the byte budget is delivered live and replaces any older cached value with a cache miss.
- Entries are evicted oldest-published first when either budget is reached.
- Expired entries are reclaimed on access and by the daemon's five-second maintenance scan.
- The cache is isolated per app, lives in daemon memory, and **resets on restart**.
- Channel queries report currently occupied channels only.

Defaults: 30-minute expiry, 1,000 channel entries, 10 MiB of encoded frames.

---

## Monitoring

### Health check

```
GET /health  →  {"status":"ok"}
```

Returns OK while the daemon's HTTP/event loop is responsive. It exposes no app identities or counters. Keep it on the internal monitoring network. A process supervisor remains responsible for recovery.

### Statistics

```php
$stats = Relay::stats(); // Signed GET /apps/{app_id}/stats via TinyCore HTTP
echo $stats->connections;
echo $stats->messages_sent;

// Equivalent, using the native Pusher client:
$stats = Relay::client()->get('/stats');
```

Requests need the app's ordinary HTTP signature and report only that app's data.

| Type | Fields |
| --- | --- |
| **Current** | `connections`, `channels`, `subscriptions`, `presence_channels`, `cached_channels`, `cache_bytes` |
| **Cumulative since daemon start** | `connections_total`, `messages_received`, `messages_sent`, `bytes_received`, `bytes_sent` |
| **Uptime** | `uptime_seconds` (monotonic clock) |

**Counting rules**

- `connections` includes sockets with no subscriptions.
- Message counters include complete WebSocket text messages and Pusher protocol events.
- "Sent" means *queued to the transport*, not acknowledged delivery.
- Byte counts exclude WebSocket, HTTP and TLS framing.
- Not counted: HTTP requests, ping/pong control frames, partial messages and rejected handshakes.
- All counters reset on restart.

> **Warning**
> Poll statistics from a backend monitoring job, never from browsers, and never expose app secrets. External monitoring must retain history and alert on disconnects or publishing failures.

---

## Limits and Failure Handling

Limits are in **bytes** unless stated otherwise. Match them to your host's capacity. A value of `0` only disables `max_connections` or the memory guard.

| Setting | Default | Behavior |
| --- | --- | --- |
| Server `max_connections` | `0` | Total established WebSockets. **Set a finite production limit.** |
| App `max_connections` | `0` | Established sockets for this app, including unsubscribed ones |
| `max_pending_connections` | `128` | Bounds HTTP requests and incomplete/rejected handshakes |
| `request_timeout` | `10` s | Absolute deadline for headers/body and HTTP response closure |
| `max_header_size` | `16,384` | Oversized HTTP headers are rejected with `431` |
| `max_request_size` | `100,000` | Oversized bodies are rejected with `413`, before `100 Continue` |
| `max_outbound_buffer_size` | `1,048,576` | Slow consumers are disconnected when queued output exceeds this |
| App `max_message_size` | `10,000` | Complete inbound WebSocket message including JSON envelope; checked while buffering |
| App `max_channels_per_connection` | `100` | Subscriptions per socket |
| App `max_presence_members` | `100` | Distinct users per presence channel (extra tabs remain allowed) |
| App `client_event_rate` | `10`/s | Per-socket token bucket, burst of 10; excess events receive `4301` |
| App `cache_ttl` | `1,800` s | Maximum age of a retained cache-channel event |
| App `max_cached_channels` | `1,000` | Retained cache entries, including unoccupied channels |
| App `max_cache_size` | `10,485,760` | Bytes of encoded cached frames per app (entry limit bounds metadata separately) |
| `activity_timeout` / `pong_timeout` | `30` / `30` s | Heartbeats, scanned every five seconds |
| `memory_limit_mb` | `256` | Checks allocated PHP memory every 30 s and exits so the supervisor can restart |

### Publishing limits

- Event data: **10,240 bytes** max
- Channels per HTTP event: **100** max
- Events per batch: **10** max
- Fragmented WebSocket messages: **1,024 fragments** max, including empty ones

If you send batches near these limits, increase `max_request_size` to allow for JSON escaping.

### HTTP API behavior

- One `Content-Length`-framed request per connection, then the connection closes.
- Chunked bodies and ambiguous lengths are rejected. The bundled PHP client always sends `Content-Length`.
- A failed batch validation publishes **nothing**.
- Signature timestamps allow up to **600 seconds** of clock skew, so keep server clocks synchronized.
- Signed URLs can be replayed during that window, and publishing is **not idempotent**.

### Debug mode

Debug mode records socket IDs, sizes and close reasons. It never records event bodies or authorization tokens.

---

## Deployment

### Commands

```sh
php spark relay:start --host=127.0.0.1 --port=8080
php spark relay:start --debug
php spark relay:restart
```

### Architecture

- Run **one daemon per app's traffic**. Route both its WebSocket connections and backend HTTP publishing to that daemon.
- Channel and cache state live in daemon memory. Persist application records in your database, and refresh client state after reconnects so views stay in sync across deployments and network interruptions.
- Terminate TLS at Nginx (or another reverse proxy) and bind the daemon to loopback or a protected network.
- Expose `/app/` to browsers; keep `/apps/` **private** for backend publishing.
- Apply connection and request rate limits at the proxy, including handshake traffic.

### Nginx

Inside your HTTPS virtual host:

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

### Supervisor

Run Relay under a process supervisor with restart enabled. The memory guard exits deliberately and relies on this.

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

Configure file descriptor limits in the supervisor's **global** configuration and OS limits, not inside the program section.

### Graceful shutdown

`SIGTERM` / `SIGINT` close clients with reconnect code `4200`, close pending HTTP sockets, and allow two seconds for close frames before forcibly releasing streams. Enable `pcntl` for signal handling.

### Restarting after a deploy

`relay:restart` writes an atomic local marker. The daemon checks it every second, closes clients with code `4200` and exits successfully, and Supervisor's `autorestart=true` starts the replacement.

After deploying, confirm the replacement is running via `/health` and your supervisor.

**Restart marker rules**

- Default location for CLI-started daemons: `storage_dir() . 'relay.restart'`.
- Run `relay:restart` as the **same OS user** as the daemon.
- Keep the marker outside the public directory, on local storage shared across releases.
- For symlink-based deployments, set server `restart_file` to a stable absolute path in both old and new releases, for example `/var/www/shared/storage/relay.restart`.
- Newly started daemons use the existing marker as their baseline, so they do not enter a restart loop.
- Programmatically constructed `Server` instances must set `restart_file` to enable polling.
- Use different paths for independently managed daemon groups. One marker deliberately restarts **every** process watching it.
- The marker mechanism works without `pcntl`.

### Event loop and capacity

ReactPHP uses the best available event-loop backend. `StreamSelectLoop` has platform-dependent descriptor limits, commonly around 1,024 per process. Install an appropriate `uv`, `ev` or `event` extension for higher concurrency, then benchmark on your deployment host to choose connection limits.

Monitor process restarts, memory, proxy connection counts, reconnect rates and publishing failures.

### Production checklist

- [ ] Unique app key and random secret; secret never shipped to the browser
- [ ] `RELAY_ALLOWED_ORIGIN` matches the real browser origin
- [ ] Finite server and per-app `max_connections`
- [ ] TLS terminated at the proxy; daemon bound to loopback or a private network
- [ ] `/apps/` and `/health` not publicly reachable
- [ ] Supervisor with `autorestart=true`
- [ ] Rate limits configured at the proxy
- [ ] Server clocks synchronized
- [ ] Monitoring and alerting on disconnects and publishing failures

---

## Testing

```sh
composer install
php tests/run.php
npm install
npm test
npm run test:load
```

The JavaScript runner starts an isolated localhost daemon on a temporary port, times out failures, and shuts the daemon down afterward.

**Coverage**

- Real `pusher-js` connections and PHP SDK publishing/authentication
- Presence tabs, whispers, batch/query APIs
- Cache replay and misses, signed statistics, health checks
- Deployment restart, raw TCP framing, memory bounds and cleanup
- Unit tests for cache expiry, eviction, app isolation, and authorization before replay

The load smoke test sends 50 events to 100 connections and requires all 5,000 deliveries. It is **not** a sustained production capacity benchmark.

**Testing against a sibling SDK checkout**

```sh
RELAY_PUSHER_SOURCE=../pusher-php-server npm run test:load
```

Release reviewed SDK and Relay changes together. Deploy your Composer lockfile, and verify your own TLS, proxy, authorization rules and workload on staging.

---

## License

MIT. See [LICENSE](LICENSE).

## References

- [Pusher WebSocket protocol](https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/)
- [Pusher HTTP API](https://pusher.com/docs/channels/library_auth_reference/rest-api/)
- [ReactPHP](https://reactphp.org/)