<?php

namespace Spark\Relay;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use Psr\Http\Message\RequestInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface as SocketConnectionInterface;
use React\Socket\SocketServer;
use Throwable;
use function count;
use function defined;
use function fnmatch;
use function function_exists;
use function in_array;
use function memory_get_usage;
use function microtime;
use function parse_url;
use function preg_match;
use function sprintf;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

class Server
{
    private ServerNegotiator $negotiator;
    private ?SocketServer $socket = null;
    /** @var array<string, Connection> */
    private array $connections = [];
    /** @var array<int, SocketConnectionInterface> */
    private array $streams = [];
    private array $pending = [];
    private array $appConnectionCounts = [];
    private array $signalHandlers = [];
    private ?TimerInterface $activityTimer = null;
    private ?TimerInterface $memoryTimer = null;
    private ?TimerInterface $restartTimer = null;
    private bool $running = false;
    private bool $shuttingDown = false;

    /**
     * @param Channels $channels
     * @param array<string, App> $apps
     * @param Api $api
     * @param array $config Server configuration array
     * @param LoopInterface|null $loop
     * @param bool $debug
     */
    public function __construct(
        public readonly Channels $channels,
        public readonly array $apps,
        public readonly Api $api,
        public readonly array $config = [],
        private ?LoopInterface $loop = null,
        public readonly bool $debug = false
    ) {
        $this->loop ??= Loop::get();
        $this->negotiator = new ServerNegotiator(new RequestVerifier(), new HttpFactory());

        foreach ([
            'max_header_size' => 16384,
            'max_request_size' => 100000,
            'max_pending_connections' => 128,
            'max_outbound_buffer_size' => 1048576,
            'activity_timeout' => 30,
            'pong_timeout' => 30,
            'max_connections' => 0,
            'memory_limit_mb' => 256,
        ] as $key => $default) {
            $minimum = in_array($key, ['max_connections', 'memory_limit_mb'], true) ? 0 : 1;
            if (filter_var($config[$key] ?? $default, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]) === false) {
                throw new \InvalidArgumentException("Relay {$key} must be an integer of at least {$minimum}.");
            }
        }
        $requestTimeout = $config['request_timeout'] ?? 10;
        if (!is_numeric($requestTimeout) || !is_finite((float) $requestTimeout) || $requestTimeout <= 0) {
            throw new \InvalidArgumentException('Relay request_timeout must be positive and finite.');
        }
    }

    public function start(): void
    {
        if ($this->running || $this->shuttingDown) {
            return;
        }

        $host = (string) ($this->config['host'] ?? '0.0.0.0');
        $port = (int) ($this->config['port'] ?? 8080);
        $restart = isset($this->config['restart_file']) ? new RestartSignal($this->config['restart_file']) : null;

        $this->socket = new SocketServer("{$host}:{$port}", [], $this->loop);
        $this->running = true;

        if ($this->debug) {
            echo "[Relay] Server listening on http://{$host}:{$port}" . PHP_EOL;
        }

        $this->socket->on('connection', $this->handleNewConnection(...));
        $this->socket->on('error', function (Throwable $error) {
            error_log('[Relay] Listener failed: ' . $error->getMessage());
            $this->stop();
        });

        // Periodic keep-alive ping/pong scanner (every 5 seconds)
        $this->activityTimer = $this->loop->addPeriodicTimer(5.0, function () {
            $this->scanActivity();
            $this->channels->cache->prune();
        });

        // Periodic memory limit guard (every 30 seconds)
        $this->memoryTimer = $this->loop->addPeriodicTimer(30.0, $this->checkMemoryGuard(...));

        if ($restart !== null) {
            $this->restartTimer = $this->loop->addPeriodicTimer(1.0, function () use ($restart) {
                try {
                    if ($restart->requested()) {
                        $this->stop(reason: 'Deployment restart');
                    }
                } catch (Throwable $error) {
                    error_log('[Relay] Restart monitor failed: ' . $error->getMessage());
                    $this->stop();
                }
            });
        }

        // Register signal handlers for graceful termination if pcntl exists
        $this->registerSignals();
    }

    public function stop(int $closeCode = Protocol::CLOSE_SERVER_SHUTDOWN, string $reason = 'Server shutdown'): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;
        $this->running = false;

        if ($this->debug) {
            echo "[Relay] Initiating graceful shutdown..." . PHP_EOL;
        }

        if ($this->activityTimer !== null) {
            $this->loop->cancelTimer($this->activityTimer);
            $this->activityTimer = null;
        }

        if ($this->memoryTimer !== null) {
            $this->loop->cancelTimer($this->memoryTimer);
            $this->memoryTimer = null;
        }

        if ($this->restartTimer !== null) {
            $this->loop->cancelTimer($this->restartTimer);
            $this->restartTimer = null;
        }

        if ($this->socket !== null) {
            $this->socket->close();
            $this->socket = null;
        }

        // Close all connected clients gracefully (code 4200 causes pusher-js to reconnect immediately)
        $active = $this->connections;
        foreach ($active as $connection) {
            try {
                $connection->close($closeCode, $reason);
            } catch (Throwable) {
            }
        }
        $this->connections = [];

        foreach ($this->pending as $id => $_) {
            $this->streams[$id]->close();
        }
        foreach ($this->signalHandlers as $signal => $handler) {
            $this->loop->removeSignal($signal, $handler);
        }
        $this->signalHandlers = [];

        // Allow up to 2 seconds for in-flight close frames to flush, then stop the event loop
        $this->loop->addTimer(2.0, function () {
            foreach ($this->streams as $stream) {
                $stream->close();
            }
            $this->loop->stop();
        });
    }

    public function getConnections(): array
    {
        return $this->connections;
    }

    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    private function handleNewConnection(SocketConnectionInterface $conn): void
    {
        if ($this->shuttingDown || count($this->pending) >= ($this->config['max_pending_connections'] ?? 128)) {
            $conn->close();
            return;
        }

        $id = spl_object_id($conn);
        $this->streams[$id] = $conn;
        $this->pending[$id] = true;
        $buffer = '';
        $connection = null;
        $request = null;
        $expectedBodyLength = 0;
        $done = false;
        $maxHeaderSize = (int) ($this->config['max_header_size'] ?? 16384);
        $maxRequestSize = (int) ($this->config['max_request_size'] ?? 100000);

        $timeout = $this->loop->addTimer((float) ($this->config['request_timeout'] ?? 10), function () use ($conn) {
            $conn->close();
        });
        $conn->on('error', function () use ($conn) {
            $conn->close();
        });
        $conn->on('close', function () use ($id, $timeout, &$done) {
            $done = true;
            $this->loop->cancelTimer($timeout);
            unset($this->streams[$id], $this->pending[$id]);
        });

        $reject = function (int $status, string $message) use ($conn, &$done) {
            $done = true;
            $this->writeRawResponse($conn, $status, ['Content-Type' => 'text/plain'], $message . "\n");
            $conn->end();
        };

        $conn->on('data', function (string $chunk) use ($conn, $id, $timeout, $maxHeaderSize, $maxRequestSize, $reject, &$buffer, &$connection, &$request, &$expectedBodyLength, &$done) {
            if ($done) {
                return;
            }
            if ($connection !== null) {
                $connection->onData($chunk);
                return;
            }

            try {
                $buffer .= $chunk;
                if ($request === null) {
                    $headerEnd = strpos($buffer, "\r\n\r\n");
                    if (
                        ($headerEnd === false && strlen($buffer) > $maxHeaderSize)
                        || ($headerEnd !== false && $headerEnd + 4 > $maxHeaderSize)
                    ) {
                        $reject(431, 'Request headers too large');
                        return;
                    }
                    if ($headerEnd === false) {
                        return;
                    }

                    try {
                        $request = Message::parseRequest(substr($buffer, 0, $headerEnd + 4));
                    } catch (Throwable) {
                        $reject(400, 'Bad Request');
                        return;
                    }
                    $buffer = substr($buffer, $headerEnd + 4);

                    // One Content-Length-framed request per socket; never guess at ambiguous framing.
                    $lengths = $request->getHeader('Content-Length');
                    if (
                        $request->hasHeader('Transfer-Encoding') || count($lengths) > 1
                        || (isset($lengths[0]) && !preg_match('/\A[0-9]+\z/', $lengths[0]))
                    ) {
                        $reject(400, 'Invalid request framing');
                        return;
                    }
                    $length = $lengths[0] ?? '0';
                    if ((float) $length > $maxRequestSize) {
                        $reject(413, 'Request body too large');
                        return;
                    }
                    $expectedBodyLength = (int) $length;

                    if (strtolower($request->getHeaderLine('Upgrade')) === 'websocket') {
                        if ($expectedBodyLength !== 0) {
                            $reject(400, 'WebSocket handshake must not contain a body');
                            return;
                        }
                        $connection = $this->upgradeConnection($conn, $request);
                        if ($connection === null) {
                            $done = true;
                            return;
                        }
                        unset($this->pending[$id]);
                        $this->loop->cancelTimer($timeout);
                        if ($buffer !== '') {
                            $connection->onData($buffer);
                        }
                        $buffer = '';
                        return;
                    }

                    $expect = strtolower($request->getHeaderLine('Expect'));
                    if ($expect !== '' && $expect !== '100-continue') {
                        $reject(417, 'Expectation failed');
                        return;
                    }
                    if ($expect === '100-continue') {
                        $conn->write("HTTP/1.1 100 Continue\r\n\r\n");
                    }
                }

                if (strlen($buffer) > $maxRequestSize) {
                    $reject(413, 'Request body too large');
                    return;
                }
                if (strlen($buffer) < $expectedBodyLength) {
                    return;
                }

                // Mark complete before publishing: later chunks cannot replay this request.
                $done = true;
                if ($request->getMethod() === 'GET' && $request->getUri()->getPath() === '/health') {
                    [$status, $headers, $body] = [
                        200,
                        ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
                        '{"status":"ok"}',
                    ];
                } else {
                    [$status, $headers, $body] = $this->api->handle(
                        $request->getMethod(),
                        $request->getUri()->getPath(),
                        $request->getUri()->getQuery(),
                        substr($buffer, 0, $expectedBodyLength),
                        $maxRequestSize,
                    );
                }
                $buffer = '';
                $this->writeRawResponse($conn, $status, $headers, $body);
                $conn->end();
            } catch (Throwable $error) {
                error_log('[Relay] Request failed: ' . get_class($error));
                if ($connection !== null) {
                    $connection->close(1011, 'Internal server error');
                } else {
                    $reject(500, 'Internal server error');
                }
            }
        });
    }

    private function upgradeConnection(SocketConnectionInterface $conn, RequestInterface $request): ?Connection
    {
        if (!preg_match('#\A/app/([^/]+)\z#', $request->getUri()->getPath(), $matches)) {
            $this->writeRawResponse($conn, 404, ['Content-Type' => 'text/plain'], "Not Found\n");
            $conn->end();
            return null;
        }

        $app = App::byKey($matches[1], $this->apps);
        if ($app === null) {
            $this->completeUpgradeAndClose($conn, $request, Protocol::CLOSE_APPLICATION_UNKNOWN, 'Application does not exist');
            return null;
        }
        if (!$this->isOriginAllowed($app, $request)) {
            $this->completeUpgradeAndClose($conn, $request, Protocol::CLOSE_APPLICATION_DISABLED, 'Origin not allowed');
            return null;
        }

        $queryParams = [];
        parse_str($request->getUri()->getQuery(), $queryParams);
        if (isset($queryParams['protocol']) && !in_array($queryParams['protocol'], ['7'], true)) {
            $this->completeUpgradeAndClose($conn, $request, 4007, 'Unsupported protocol version');
            return null;
        }

        $maxConnections = (int) ($this->config['max_connections'] ?? 0);
        $appConnections = $this->appConnectionCounts[$app->appId] ?? 0;
        if (
            ($maxConnections > 0 && count($this->connections) >= $maxConnections)
            || ($app->maxConnections > 0 && $appConnections >= $app->maxConnections)
        ) {
            $this->completeUpgradeAndClose($conn, $request, Protocol::CLOSE_OVER_CONNECTION_LIMIT, 'Over connection limit');
            return null;
        }

        $handshake = $this->negotiator->handshake($request);
        $conn->write(Message::toString($handshake));
        if ($handshake->getStatusCode() !== 101) {
            $conn->end();
            return null;
        }

        $socketId = Protocol::newSocketId(fn($id) => isset($this->connections[$id]));
        $connection = new Connection(
            stream: $conn,
            socketId: $socketId,
            app: $app,
            channels: $this->channels,
            queryParams: $queryParams,
            debug: $this->debug,
            maxOutboundBufferSize: (int) ($this->config['max_outbound_buffer_size'] ?? 1048576),
            loop: $this->loop,
            metrics: $this->api->metrics,
        );
        $this->connections[$socketId] = $connection;
        $this->appConnectionCounts[$app->appId] = $appConnections + 1;
        $this->api->metrics->connected($app->appId);
        $connection->onClose(function () use ($socketId, $app) {
            unset($this->connections[$socketId]);
            $this->api->metrics->disconnected($app->appId);
            if (--$this->appConnectionCounts[$app->appId] === 0) {
                unset($this->appConnectionCounts[$app->appId]);
            }
        });
        $connection->send(Protocol::connectionEstablished($socketId, (int) ($this->config['activity_timeout'] ?? 30)));

        return $connection;
    }

    private function completeUpgradeAndClose(
        SocketConnectionInterface $conn,
        RequestInterface $request,
        int $errorCode,
        string $errorMessage
    ): void {
        $handshake = $this->negotiator->handshake($request);
        $conn->write(Message::toString($handshake));

        if ($handshake->getStatusCode() !== 101) {
            $conn->end();
            return;
        }

        // Send pusher:error frame first
        $errorFrame = Protocol::error($errorCode, $errorMessage);
        $f = new \Ratchet\RFC6455\Messaging\Frame($errorFrame, true, \Ratchet\RFC6455\Messaging\Frame::OP_TEXT);
        $conn->write($f->getContents());

        // Then send close frame with code
        $closePayload = pack('n', $errorCode) . $errorMessage;
        $cf = new \Ratchet\RFC6455\Messaging\Frame($closePayload, true, \Ratchet\RFC6455\Messaging\Frame::OP_CLOSE);
        $conn->write($cf->getContents());
        $conn->end();
    }

    private function isOriginAllowed(App $app, RequestInterface $request): bool
    {
        $allowed = $app->allowedOrigins;
        if (in_array('*', $allowed, true)) {
            return true;
        }

        $origin = $request->getHeaderLine('Origin');
        $parts = parse_url($origin);
        if (
            count($request->getHeader('Origin')) !== 1 || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])
        ) {
            return false;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $hostWithPort = $port ? "{$host}:{$port}" : $host;

        foreach ($allowed as $pattern) {
            if (
                str_contains($pattern, '://')
                ? fnmatch($pattern, $origin)
                : (fnmatch($pattern, (string) $host) || fnmatch($pattern, (string) $hostWithPort))
            ) {
                return true;
            }
        }

        return false;
    }

    private function writeRawResponse(
        SocketConnectionInterface $conn,
        int $statusCode,
        array $headers,
        string $body
    ): void {
        $statusTexts = [
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            417 => 'Expectation Failed',
            431 => 'Request Header Fields Too Large',
            413 => 'Payload Too Large',
            500 => 'Internal Server Error',
        ];
        $phrase = $statusTexts[$statusCode] ?? 'Unknown';
        $headers['Content-Length'] = (string) strlen($body);
        $headers['Connection'] = 'close';

        $out = "HTTP/1.1 {$statusCode} {$phrase}\r\n";
        foreach ($headers as $k => $v) {
            $out .= "{$k}: {$v}\r\n";
        }
        $out .= "\r\n{$body}";

        $conn->write($out);
    }

    private function scanActivity(): void
    {
        $now = microtime(true);
        $activityTimeout = (int) ($this->config['activity_timeout'] ?? 30);
        $pongTimeout = (int) ($this->config['pong_timeout'] ?? 30);

        foreach ($this->connections as $socketId => $connection) {
            $idle = $now - $connection->getLastActivity();

            if ($connection->getPingSentAt() !== null) {
                if (($now - $connection->getPingSentAt()) >= $pongTimeout) {
                    if ($this->debug) {
                        echo "[Relay] Closing socket {$socketId} due to pong timeout." . PHP_EOL;
                    }
                    $connection->close(Protocol::CLOSE_PONG_TIMEOUT, 'Pong timeout');
                    unset($this->connections[$socketId]);
                }
            } else {
                if ($idle >= $activityTimeout) {
                    $connection->send(Protocol::ping());
                    $connection->setPingSentAt($now);
                }
            }
        }
    }

    private function checkMemoryGuard(): void
    {
        $limitMb = (int) ($this->config['memory_limit_mb'] ?? 256);
        if ($limitMb <= 0) {
            return;
        }

        $usedMb = memory_get_usage(true) / 1024 / 1024;
        if ($usedMb >= $limitMb) {
            error_log(sprintf('[Relay] Memory (%.2f MB) exceeded limit (%d MB). Shutting down.', $usedMb, $limitMb));
            $this->stop(Protocol::CLOSE_SERVER_SHUTDOWN, 'Memory limit exceeded');
        }
    }

    private function registerSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal) {
            if ($this->debug) {
                echo "[Relay] Received signal {$signal}. Shutting down..." . PHP_EOL;
            }
            $this->stop(Protocol::CLOSE_SERVER_SHUTDOWN, 'Received termination signal');
        };

        if (defined('SIGTERM')) {
            $this->signalHandlers[SIGTERM] = $handler;
            $this->loop->addSignal(SIGTERM, $handler);
        }
        if (defined('SIGINT')) {
            $this->signalHandlers[SIGINT] = $handler;
            $this->loop->addSignal(SIGINT, $handler);
        }
    }
}
