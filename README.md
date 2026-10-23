# Relay: Pusher-Compatible WebSocket Server for TinyMVC / Spark

A high-performance, single-node Pusher-compatible WebSocket server and HTTP API daemon built specifically for the **TinyMVC / Spark PHP Framework**.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-49%20passed-brightgreen.svg)](tests/)

---

## Overview & Architecture

**Relay** allows your TinyMVC Spark application to broadcast real-time events over WebSockets to standard browser clients without needing third-party SaaS services or external Redis daemons.

```
Browser (pusher-js) ──ws──► Nginx (/app/) ──► Relay Daemon :8080 ◄──http── Spark App / Jobs
                                                     │                     (shahinmoyshan/pusher-php-server)
                                              In-Memory State:
                                              ├── Sockets & Channel Rosters
                                              ├── Presence Tracking
                                              └── Activity & Memory Timers
                                                     ▲
Browser ──POST /broadcasting/auth────────────────────┘ (Spark App authorizes private/presence channels)
```

### Key Highlights

* **100% Pusher Protocol Symmetry**: Fully compatible with official client SDKs (such as `pusher-js` v7/v8) and backend SDKs like [`shahinmoyshan/pusher-php-server`](https://github.com/shahinmoyshan/pusher-php-server).
* **Single Unified Process**: Serves both WebSocket connections (`/app/{key}`) and the Pusher REST HTTP API (`/apps/{id}/...`) simultaneously on a single event-driven port powered by ReactPHP.
* **Pure In-Memory State**: Sockets, channel memberships, and presence rosters are tracked entirely in-memory with $O(1)$ channel lookups and efficient $O(\text{subscriptions})$ disconnect cleanups. Zero external database or Redis dependency required.
* **Channel Support**: Full native support for **public**, **private** (`private-*`), and **presence** (`presence-*`) channels, along with client events / whispers (`client-*`).
* **High-Concurrency Scaling (`ext-uv` / libuv)**: ReactPHP automatically detects and activates `ext-uv` (the same C event engine that powers Node.js) or `ext-ev`/`ext-event` to break past the 1,024 file descriptor limit of `stream_select()`, scaling effortlessly to tens of thousands of concurrent connections with zero code changes.
* **Robust Daemon Reliability**: Includes activity heartbeats (`pusher:ping` / `pusher:pong`), pong timeout scanning, origin filtering, timing-safe HMAC-SHA256 signature verification, and memory threshold auto-guarding.

---

## Installation

```bash
composer require tinymvc/relay
```

> **Requirements:** PHP 8.2 or higher, `ext-json`. On Linux, `ext-pcntl` is recommended for graceful POSIX signal handling.

---

## Configuration

Publish or place the configuration file at `config/relay.php`:

```php
<?php

return [
    'server' => [
        'host'              => env('RELAY_SERVER_HOST', '0.0.0.0'),
        'port'              => (int) env('RELAY_SERVER_PORT', 8080),
        'activity_timeout'  => (int) env('RELAY_ACTIVITY_TIMEOUT', 30),
        'pong_timeout'      => (int) env('RELAY_PONG_TIMEOUT', 30),
        'max_connections'   => (int) env('RELAY_MAX_CONNECTIONS', 0), // 0 = unlimited
        'max_request_size'  => (int) env('RELAY_MAX_REQUEST_SIZE', 100000), // bytes
        'memory_limit_mb'   => (int) env('RELAY_MEMORY_LIMIT_MB', 256),
    ],
    'apps' => [
        [
            'app_id'           => env('RELAY_APP_ID', '1'),
            'key'              => env('RELAY_APP_KEY', 'spark-relay-key'),
            'secret'           => env('RELAY_APP_SECRET', 'spark-relay-secret'),
            'allowed_origins'  => ['*'], // In production, specify: ['https://example.com']
            'client_events'    => (bool) env('RELAY_CLIENT_EVENTS', false),
            'max_message_size' => (int) env('RELAY_MAX_MESSAGE_SIZE', 10000),
        ],
    ],
    'client' => [
        'host'    => env('RELAY_HOST', '127.0.0.1'),
        'port'    => (int) env('RELAY_PORT', 8080),
        'scheme'  => env('RELAY_SCHEME', 'http'),
        'timeout' => 5,
    ],
];
```

### Environment Variables

| Variable | Default | Purpose |
| :--- | :--- | :--- |
| `RELAY_SERVER_HOST` | `0.0.0.0` | IP/Host for the Relay daemon to bind. |
| `RELAY_SERVER_PORT` | `8080` | Port for the Relay daemon. |
| `RELAY_APP_ID` | `1` | Default Pusher application ID. |
| `RELAY_APP_KEY` | `spark-relay-key` | Application public key. |
| `RELAY_APP_SECRET` | `spark-relay-secret` | Application private secret used for HMAC signatures. |
| `RELAY_HOST` | `127.0.0.1` | Host for backend Spark jobs to connect to Relay. |
| `RELAY_PORT` | `8080` | Port for backend Spark jobs to connect to Relay. |
| `RELAY_SCHEME` | `http` | HTTP scheme (`http` or `https`) for backend API requests. |
| `VITE_RELAY_APP_KEY` | `spark-relay-key` | Frontend client build key. |
| `VITE_RELAY_HOST` | `window.location.hostname` | Frontend client WebSocket host. |
| `VITE_RELAY_PORT` | `8080` / `443` | Frontend client WebSocket port. |

---

## Running the Daemon

Start the server using the Spark CLI command:

```bash
php spark relay:start
```

### Command Options

```bash
# Custom host and port
php spark relay:start --host=127.0.0.1 --port=8088

# Verbose debug mode (logs all frames with secrets automatically redacted)
php spark relay:start --debug
```

Example startup output:
```text
  INFO   Relay WebSocket & HTTP API Server starting...
  Address:    [http://0.0.0.0:8080]
  Apps:       [1]
  Event Loop: [React\EventLoop\StreamSelectLoop]
  Press Ctrl+C to stop the server.
```

---

## Event Loop Backends & High-Concurrency Scaling (`ext-uv`)

Relay is powered by **ReactPHP's unified Event Loop** (`React\EventLoop\Loop`). ReactPHP automatically inspects available PHP extensions at startup and selects the fastest, most scalable backend loop available on the system:

| Loop Backend | Extension Required | Underlying Engine | Concurrent Limit | Recommended Use |
| :--- | :--- | :--- | :--- | :--- |
| `StreamSelectLoop` | *None* (PHP standard) | `stream_select()` | $\approx 1,024$ connections | Local development, testing, and small-to-medium apps. |
| `ExtUvLoop` | [`ext-uv`](https://github.com/bwoebi/php-uv) | **libuv** (Node.js engine) | **Unlimited** ($10,000+$) | **Recommended for high-concurrency production.** |
| `ExtEvLoop` | `ext-ev` | **libev** / epoll / kqueue | **Unlimited** ($10,000+$) | High-performance production alternative. |
| `ExtEventLoop` | `ext-event` | **libevent** / epoll / kqueue | **Unlimited** ($10,000+$) | High-performance production alternative. |

### Why `ext-uv` for Production?

By default, without any compiled extensions, PHP relies on `StreamSelectLoop` via `stream_select()`. Operating systems cap `stream_select()` to `FD_SETSIZE` (typically 1,024 file descriptors). When your application approaches 1,000 simultaneous WebSocket connections, `stream_select()` can no longer monitor additional sockets.

**The Fix:** Simply install the `ext-uv` PHP extension on your server.
* **Zero Code Changes**: Relay uses `React\EventLoop\Loop::get()`, which prioritizes `ext-uv` as its top choice.
* **Instant Scalability**: As soon as `ext-uv` is enabled in `php.ini`, Relay automatically switches from `StreamSelectLoop` to `ExtUvLoop`.
* **Identical Application Behavior**: The extension only replaces the low-level I/O polling mechanism (`epoll` on Linux, `kqueue` on macOS/BSD, `IOCP` on Windows). Your application code, channel authorizers, and WebSocket event handlers remain standard PHP callbacks.

Upon startup, `php spark relay:start` reports the active loop engine:

```text
  INFO   Relay WebSocket & HTTP API Server starting...
  Address:    [http://0.0.0.0:8080]
  Apps:       [1]
  Event Loop: [React\EventLoop\ExtUvLoop]
```

### Installing `ext-uv` on Linux

```bash
# Ubuntu / Debian prerequisites
sudo apt-get install libuv1-dev

# Install via PECL
sudo pecl install uv

# Enable extension in php.ini
echo "extension=uv.so" | sudo tee /etc/php/8.2/cli/conf.d/20-uv.ini
```

---

## Backend Broadcasting

### Using the `Relay` Facade

```php
use Spark\Relay\Facades\Relay;

// Broadcast an event to one or more channels
Relay::trigger('orders', 'order.created', [
    'order_id' => $order->id,
    'total'    => $order->total,
]);

// Exclude originating socket ID (prevents sender from receiving duplicate updates)
Relay::trigger('chat.room.5', 'message.sent', [
    'text' => $message->text,
], $request->input('socket_id'));
```

### Queued Broadcast Jobs

Dispatch broadcasts asynchronously via Spark Queue:

```php
namespace App\Jobs;

use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;
use Spark\Relay\Facades\Relay;

class BroadcastRelayEvent implements JobInterface
{
    use Dispatchable;

    public function __construct(
        public array|string $channels,
        public string $event,
        public array $data,
        public ?string $socketId = null
    ) {}

    public function handle(): void
    {
        Relay::trigger($this->channels, $this->event, $this->data, $this->socketId);
    }
}
```

Dispatch anywhere in your application:

```php
BroadcastRelayEvent::dispatch('chat.5', 'message.new', ['id' => $msg->id])
    ->onQueue('relay');
```

---

## Channel Authorization

Channels with `private-*` or `presence-*` prefixes require client authentication via an HMAC signature.

### 1. Define Authorization Rules

Define authorization rules in `routes/channels.php` or a service provider:

```php
use Spark\Relay\ChannelAuth;

// Private channel: callback returns bool
ChannelAuth::define('private-chat.{conversationId}', function ($user, $conversationId) {
    return $user && $user->canAccessConversation($conversationId);
});

// Presence channel: callback returns user presence array or false
ChannelAuth::define('presence-workspace.{workspaceId}', function ($user, $workspaceId) {
    if (!$user || !$user->belongsToWorkspace($workspaceId)) {
        return false;
    }

    return [
        'id'   => (string) $user->id,
        'info' => [
            'name'   => $user->name,
            'avatar' => $user->avatar_url,
        ],
    ];
});
```

### 2. Broadcasting Authentication Route & Controller

Create `app/Http/Controllers/BroadcastingController.php`:

```php
namespace App\Http\Controllers;

use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Relay\ChannelAuth;
use Spark\Relay\Facades\Relay;

class BroadcastingController extends Controller
{
    public function authenticate(Request $request): Response
    {
        $user = auth()->user();
        if (!$user) {
            return json(['error' => 'Unauthenticated'], 403);
        }

        $socketId = (string) $request->input('socket_id');
        $channel  = (string) $request->input('channel_name');

        $authResult = ChannelAuth::authorize($user, $channel);
        if ($authResult === false) {
            return json(['error' => 'Forbidden'], 403);
        }

        $presence = is_array($authResult) ? $authResult : null;
        $authPayload = Relay::authorize($channel, $socketId, $presence);

        // Return raw JSON response string to avoid double encoding
        return response($authPayload, 200, ['Content-Type' => 'application/json']);
    }
}
```

Register the endpoint in `routes/web.php`:

```php
use App\Http\Controllers\BroadcastingController;
use Spark\Facades\Route;

Route::post('/broadcasting/auth', [BroadcastingController::class, 'authenticate']);
```

---

## Frontend Integration (`pusher-js`)

Install `pusher-js` in your frontend application:

```bash
npm install pusher-js
```

### Initialize Client

```typescript
import Pusher from 'pusher-js';

export const pusher = new Pusher(import.meta.env.VITE_RELAY_APP_KEY, {
    cluster: 'mt1',
    wsHost: import.meta.env.VITE_RELAY_HOST || window.location.hostname,
    wsPort: Number(import.meta.env.VITE_RELAY_PORT || 8080),
    wssPort: Number(import.meta.env.VITE_RELAY_PORT || 443),
    forceTLS: import.meta.env.VITE_RELAY_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    channelAuthorization: {
        endpoint: '/broadcasting/auth',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
    },
});

// 1. Public Channel
const news = pusher.subscribe('news');
news.bind('headline', (data) => {
    console.log('Breaking news:', data);
});

// 2. Private Channel
const chat = pusher.subscribe('private-chat.42');
chat.bind('message.sent', (data) => {
    console.log('New message:', data);
});

// 3. Presence Channel
const presence = pusher.subscribe('presence-workspace.main');
presence.bind('pusher:subscription_succeeded', (members) => {
    console.log('Online count:', members.count);
    members.each((member) => console.log('Member:', member.info));
});
presence.bind('pusher:member_added', (member) => {
    console.log('Joined:', member.info);
});
presence.bind('pusher:member_removed', (member) => {
    console.log('Left:', member.id);
});
```

---

## Production Deployment

### 1. Supervisor Daemon (`/etc/supervisor/conf.d/relay.conf`)

```ini
[program:relay]
process_name=%(program_name)s
command=php /var/www/app/spark relay:start
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/app/storage/logs/relay.log
stopsignal=TERM
stopwaitsecs=10
minfds=65535
```

> **Note on File Descriptors:** When handling thousands of concurrent connections with `ext-uv`, ensure your system limits allow it. Set `minfds=65535` in Supervisor and verify `ulimit -n` on your host.

Reload supervisor:
```bash
supervisorctl reread
supervisorctl update
supervisorctl start relay
```

### 2. Nginx Reverse Proxy Configuration

```nginx
# Public WebSocket Endpoint for browser clients
location /app/ {
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection upgrade;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_read_timeout 120s;
    proxy_send_timeout 120s;
    proxy_pass http://127.0.0.1:8080;
}

# IMPORTANT: Do NOT proxy /apps/ publicly!
# /apps/ is strictly internal for backend SDK API requests.
```

---

## Security Best Practices

* **Timing-Safe Signatures**: All signature comparisons use `hash_equals()`.
* **HMAC API Authentication**: All REST API requests (`/apps/{id}/...`) require valid `auth_signature` within a strict timestamp window ($\le 600\text{s}$).
* **Payload Integrity**: POST requests verify `body_md5` before body parsing.
* **Internal API Isolation**: Never expose `/apps/` publicly in Nginx. Only `/app/` should be forwarded to the browser.
* **Origin Whitelisting**: Set explicit `allowed_origins` in `config/relay.php` rather than wildcard `*` in production.
* **Secret Redaction**: Debug loggers automatically redact authentication secrets and tokens.

---

## Running Tests

Execute the unit test suite:

```bash
composer test
# or
php tests/run.php
```

All 49 unit and protocol tests run in under 50ms:

```text
Tests:    49 passed (187 assertions)
Duration: ~0.03s
```

Run the end-to-end integration test suite (requires Node and `pusher-js`):

```bash
node tests/e2e/e2e_test.cjs
```

---

## License

This package is open-source software licensed under the [MIT license](LICENSE).
Built with ❤️ by [Shahin Moyshan](https://github.com/shahinmoyshan) for the TinyMVC / Spark ecosystem.
