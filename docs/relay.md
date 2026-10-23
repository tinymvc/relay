# Relay: Pusher-Compatible WebSocket Server for TinyMVC / Spark

Contents:

- [Architecture and Overview](#architecture-and-overview)
- [Source Paths and Classes](#source-paths-and-classes)
- [Configuration](#configuration)
- [Console Commands](#console-commands)
- [Backend Integration (Web and Queue)](#backend-integration-web-and-queue)
- [Channel Authorization](#channel-authorization)
- [Frontend Integration (pusher-js)](#frontend-integration-pusher-js)
- [Production Setup (Supervisor and Nginx)](#production-setup-supervisor-and-nginx)
- [Security Checklist](#security-checklist)
- [Known Pitfalls and Solutions](#known-pitfalls-and-solutions)
- [Testing and Verification](#testing-and-verification)

---

## Architecture and Overview

**Relay** is a high-performance, single-node Pusher-compatible WebSocket server and HTTP API daemon built for TinyMVC / Spark framework.

```
Browser (pusher-js) --ws--> Nginx /app/ --> Relay :8080 <--http-- Spark app / queue job (pusher-php-server)
                                             in-memory: connections, channels, presence
                                             timers: activity/ping scan, memory guard
Browser --POST /broadcasting/auth--> Spark app (signs channel auth with app secret)
```

- **Browser**: Plain `pusher-js` (v7 or v8), no custom wrapper or Echo needed.
- **Backend**: Official `pusher/pusher-php-server` SDK pointed to Relay by host and port.
- **Single Process**: Serves both WebSocket connections (`/app/{key}`) and HTTP API (`/apps/{id}/...`) on a single port.
- **Pure In-Memory State**: Channels, subscriber registries, and presence rosters are tracked efficiently in-memory with O(subscriptions) disconnect cleanup.

---

## Source Paths and Classes

All daemon source files are contained within `tinymvc/relay`:

| File | Purpose |
| --- | --- |
| `src/Server.php` | Socket server, upgrade vs API routing, activity & memory timers, shutdown |
| `src/Connection.php` | WS connection wrapper, MessageBuffer, frame dispatcher, keep-alive tracking |
| `src/Channels.php` | Registry for public, private, and presence channels, fan-out, whispers, queries |
| `src/Protocol.php` | Pusher protocol constants, frame formatting, validators, socket ID generators |
| `src/Signature.php` | HMAC-SHA256 signer and validator for channel auth and API requests |
| `src/App.php` | App value object and repository (`byKey`, `byId`, `loadAll`) |
| `src/Api.php` | Pusher HTTP API endpoints (`events`, `batch_events`, `channels`, `users`) |
| `src/Relay.php` | Backend helper wrapping the official Pusher SDK client (`trigger`, `authorize`) |
| `src/ChannelAuth.php` | Pattern-based channel authorization registry (`define`, `authorize`) |
| `src/RelayServiceProvider.php` | Framework service provider registering `relay` and `relay:start` command |
| `src/Facades/Relay.php` | Facade for `Spark\Relay\Relay` (`Spark\Relay\Facades\Relay`) |
| `src/Console/StartCommand.php` | CLI handler for `php spark relay:start` |
| `config/relay.php` | Default configuration file |

---

## Configuration

Place configuration in `config/relay.php`:

```php
<?php

return [
    'server' => [
        'host' => env('RELAY_SERVER_HOST', '0.0.0.0'),
        'port' => (int) env('RELAY_SERVER_PORT', 8080),
        'activity_timeout' => (int) env('RELAY_ACTIVITY_TIMEOUT', 30),
        'pong_timeout' => (int) env('RELAY_PONG_TIMEOUT', 30),
        'max_connections' => (int) env('RELAY_MAX_CONNECTIONS', 0), // 0 = unlimited
        'max_request_size' => (int) env('RELAY_MAX_REQUEST_SIZE', 100000), // bytes
        'memory_limit_mb' => (int) env('RELAY_MEMORY_LIMIT_MB', 256),
    ],
    'apps' => [
        [
            'app_id' => env('RELAY_APP_ID', '1'),
            'key' => env('RELAY_APP_KEY', 'spark-relay-key'),
            'secret' => env('RELAY_APP_SECRET', 'spark-relay-secret'),
            'allowed_origins' => ['*'],
            'client_events' => (bool) env('RELAY_CLIENT_EVENTS', false),
            'max_message_size' => (int) env('RELAY_MAX_MESSAGE_SIZE', 10000),
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

Environment variables:
- `RELAY_APP_ID`, `RELAY_APP_KEY`, `RELAY_APP_SECRET`: App credentials.
- `RELAY_SERVER_HOST`, `RELAY_SERVER_PORT`: Listen host and port for daemon.
- `RELAY_HOST`, `RELAY_PORT`, `RELAY_SCHEME`: Endpoint for backend app to reach Relay.
- `VITE_RELAY_APP_KEY`, `VITE_RELAY_HOST`, `VITE_RELAY_PORT`: Frontend client build variables.

---

## Console Commands

### Start Relay Server

```bash
php spark relay:start
```

Options:
- `--host=0.0.0.0`: Override listen host
- `--port=8080`: Override listen port
- `--debug`: Enable verbose debug output of inbound/outbound frames with redacted auth keys

Example output:
```text
  INFO   Relay WebSocket & HTTP API Server starting...
  Address:    [http://0.0.0.0:8080]
  Apps:       [1]
  Event Loop: [React\EventLoop\StreamSelectLoop]
  Press Ctrl+C to stop the server.
```

---

## Backend Integration (Web and Queue)

### Using the Relay Facade

```php
use Spark\Relay\Facades\Relay;

// Broadcast to public or private channels
Relay::trigger('orders', 'order.created', [
    'order_id' => $order->id,
    'total' => $order->total,
]);

// Exclude the originating socket (sender) to prevent duplicate self-updates
Relay::trigger('chat.room.5', 'message.sent', [
    'text' => $message->text,
], $request->input('socket_id'));
```

### Queued Broadcast Job

Queue event delivery using a standard Spark queue job:

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

Dispatch inside an action after database commit:

```php
BroadcastRelayEvent::dispatch('chat.5', 'message.new', ['id' => $msg->id])
    ->onQueue('relay');
```

---

## Channel Authorization

Define channel rules in `routes/channels.php` (or in a service provider):

```php
use Spark\Relay\ChannelAuth;

// Private channel: returns bool
ChannelAuth::define('private-chat.{conversationId}', function ($user, $conversationId) {
    return $user && $user->canAccessConversation($conversationId);
});

// Presence channel: returns presence array with id and info, or false
ChannelAuth::define('presence-workspace.{workspaceId}', function ($user, $workspaceId) {
    if (!$user || !$user->belongsToWorkspace($workspaceId)) {
        return false;
    }

    return [
        'id' => (string) $user->id,
        'info' => [
            'name' => $user->name,
            'role' => $user->role,
        ],
    ];
});
```

### Broadcasting Auth Controller

Thin controller in `app/Http/Controllers/BroadcastingController.php`:

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
        $channel = (string) $request->input('channel_name');

        $authResult = ChannelAuth::authorize($user, $channel);
        if ($authResult === false) {
            return json(['error' => 'Forbidden'], 403);
        }

        $presence = is_array($authResult) ? $authResult : null;
        $authPayload = Relay::authorize($channel, $socketId, $presence);

        // Crucial: return raw JSON string with Content-Type header to avoid double encoding
        return response($authPayload, 200, ['Content-Type' => 'application/json']);
    }
}
```

Register route in `routes/web.php`:

```php
use App\Http\Controllers\BroadcastingController;
use Spark\Facades\Route;

Route::post('/broadcasting/auth', [BroadcastingController::class, 'authenticate']);
```

---

## Frontend Integration (pusher-js)

Install `pusher-js` in your frontend:

```bash
npm install pusher-js
```

Initialize client:

```ts
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

// Subscribe to public channel
const news = pusher.subscribe('news');
news.bind('headline', (data) => {
    console.log('Breaking news:', data);
});

// Subscribe to private channel
const chat = pusher.subscribe('private-chat.42');
chat.bind('message.sent', (data) => {
    console.log('New message:', data);
});

// Subscribe to presence channel
const presence = pusher.subscribe('presence-workspace.main');
presence.bind('pusher:subscription_succeeded', (members) => {
    console.log('Online members count:', members.count);
    members.each((member) => console.log('Member:', member.info));
});
presence.bind('pusher:member_added', (member) => {
    console.log('Member joined:', member.info);
});
presence.bind('pusher:member_removed', (member) => {
    console.log('Member left:', member.id);
});
```

---

## Production Setup (Supervisor and Nginx)

### Supervisor Configuration

Create `/etc/supervisor/conf.d/relay.conf`:

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
minfds=10000
```

Deploy restart command:
```bash
supervisorctl restart relay
```

### Nginx Reverse Proxy Configuration

```nginx
# Public WebSocket endpoint
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

# IMPORTANT: Do NOT expose /apps/ publicly!
# /apps/ is internal for the backend SDK only.
```

---

## Security Checklist

- [x] **Timing-Safe Signatures**: All signature comparisons use `hash_equals()`.
- [x] **HMAC API Authentication**: All API requests (`/apps/{id}/...`) require valid `auth_signature` and timestamp window (`<= 600s`).
- [x] **Body Integrity**: POST requests verify `body_md5` against raw payload bytes before parsing.
- [x] **Channel Authorization**: Private and presence channel subscriptions require authentic HMAC signature generated with app secret.
- [x] **Origin Isolation**: In production, configure explicit `allowed_origins` rather than `*`.
- [x] **Internal API**: Nginx must only proxy `/app/`. Never expose `/apps/` to the internet.
- [x] **Secret Redaction**: Debug logging automatically redacts auth keys and credentials.
- [x] **Client Whispers**: Whispers restricted to `client-*` events on private or presence channels from subscribed clients when enabled.

---

## Known Pitfalls and Solutions

1. **Double JSON Encoding**: Server-sent `data` must be a JSON string. Relay ensures forwarded API payloads are never double-encoded.
2. **Empty Array vs Object in JSON**: Empty PHP arrays `[]` encode as arrays. Relay guarantees presence `hash` and API `channels` are explicitly cast to objects `{}`.
3. **Socket ID Regex**: The Pusher SDK requires socket IDs matching `^\d+\.\d+$`. Relay enforces this pattern.
4. **Presence Signature String**: Presence HMAC signs `socket_id:channel_name:channel_data` using the exact raw JSON string sent by the client.
5. **Multiple Tabs of One User**: `member_added` fires only on the first socket for that user; `member_removed` fires only when the user's last socket disconnects.
6. **Concurrent Iteration**: Fan-out snapshots connection lists before iterating so connections dropping mid-iteration do not break loops.
7. **4009 Channel Auth Error**: On failed channel authorization, Relay sends a `pusher:error` with code 4009 and leaves the socket open.

---

## Testing and Verification

Run the full unit test suite:

```bash
cd relay
php test
```

Run the end-to-end integration test suite (requires Node and `pusher-js`):

```bash
cd relay
node tests/e2e/e2e_test.cjs
```
