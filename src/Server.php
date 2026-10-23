<?php

namespace Spark\Relay;

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
use function strpos;
use function strtolower;
use function substr;

class Server
{
    private ServerNegotiator $negotiator;
    private ?SocketServer $socket = null;
    /** @var array<string, Connection> */
    private array $connections = [];
    private ?TimerInterface $activityTimer = null;
    private ?TimerInterface $memoryTimer = null;
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
        $prev = error_reporting(error_reporting() & ~E_DEPRECATED);
        $this->negotiator = new ServerNegotiator(new RequestVerifier());

        error_reporting($prev);
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $host = (string) ($this->config['host'] ?? '0.0.0.0');
        $port = (int) ($this->config['port'] ?? 8080);

        $this->socket = new SocketServer("{$host}:{$port}", [], $this->loop);
        $this->running = true;

        if ($this->debug) {
            echo "[Relay] Server listening on http://{$host}:{$port}" . PHP_EOL;
        }

        $this->socket->on('connection', $this->handleNewConnection(...));

        // Periodic keep-alive ping/pong scanner (every 5 seconds)
        $this->activityTimer = $this->loop->addPeriodicTimer(5.0, $this->scanActivity(...));

        // Periodic memory limit guard (every 30 seconds)
        $this->memoryTimer = $this->loop->addPeriodicTimer(30.0, $this->checkMemoryGuard(...));

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

        // Allow up to 2 seconds for in-flight close frames to flush, then stop the event loop
        $this->loop->addTimer(2.0, function () {
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
        if ($this->shuttingDown) {
            $conn->end();
            return;
        }

        $buffer = '';
        $isWebSocket = false;
        $connection = null;
        $parsedRequest = null;
        $expectedBodyLength = 0;
        $bodyBuffer = '';

        $conn->on('data', function (string $chunk) use ($conn, &$buffer, &$isWebSocket, &$connection, &$parsedRequest, &$expectedBodyLength, &$bodyBuffer) {
            if ($isWebSocket && $connection !== null) {
                $connection->onData($chunk);
                return;
            }

            $buffer .= $chunk;

            if ($parsedRequest === null) {
                $headerEnd = strpos($buffer, "\r\n\r\n");
                if ($headerEnd === false) {
                    return; // Wait for full headers
                }

                $rawHeaders = substr($buffer, 0, $headerEnd);
                $leftover = substr($buffer, $headerEnd + 4);

                try {
                    $parsedRequest = Message::parseRequest($rawHeaders . "\r\n\r\n");
                } catch (Throwable) {
                    $this->writeRawResponse($conn, 400, ['Content-Type' => 'text/plain'], "Bad Request\n");
                    $conn->end();
                    return;
                }

                $upgrade = strtolower($parsedRequest->getHeaderLine('Upgrade'));

                // Branch: WebSocket Upgrade
                if ($upgrade === 'websocket') {
                    $path = $parsedRequest->getUri()->getPath();

                    if (!preg_match('#^/app/([^/]+)$#', $path, $matches)) {
                        $this->writeRawResponse($conn, 404, ['Content-Type' => 'text/plain'], "Not Found\n");
                        $conn->end();
                        return;
                    }

                    $appKey = $matches[1];
                    $app = App::byKey($appKey, $this->apps);

                    // 1. Unknown app key: complete upgrade, send pusher:error 4001, and close
                    if ($app === null) {
                        $this->completeUpgradeAndClose(
                            $conn,
                            $parsedRequest,
                            Protocol::CLOSE_APPLICATION_UNKNOWN,
                            'Application does not exist'
                        );
                        return;
                    }

                    // 2. Check origin
                    if (!$this->isOriginAllowed($app, $parsedRequest)) {
                        $this->completeUpgradeAndClose(
                            $conn,
                            $parsedRequest,
                            Protocol::CLOSE_APPLICATION_DISABLED,
                            'Origin not allowed'
                        );
                        return;
                    }

                    // 3. Max connections check
                    $maxConnections = (int) ($this->config['max_connections'] ?? 0);
                    if ($maxConnections > 0 && count($this->connections) >= $maxConnections) {
                        $this->completeUpgradeAndClose(
                            $conn,
                            $parsedRequest,
                            Protocol::CLOSE_OVER_CONNECTION_LIMIT,
                            'Over connection limit'
                        );
                        return;
                    }

                    // 4. Perform successful handshake
                    $handshake = $this->negotiator->handshake($parsedRequest);
                    if ($handshake->getStatusCode() !== 101) {
                        $conn->write(Message::toString($handshake));
                        $conn->end();
                        return;
                    }

                    $conn->write(Message::toString($handshake));
                    $isWebSocket = true;

                    $socketId = Protocol::newSocketId(fn($id) => isset($this->connections[$id]));
                    $queryParams = [];
                    parse_str($parsedRequest->getUri()->getQuery(), $queryParams);

                    $connection = new Connection(
                        stream: $conn,
                        socketId: $socketId,
                        app: $app,
                        channels: $this->channels,
                        queryParams: $queryParams,
                        debug: $this->debug
                    );

                    $this->connections[$socketId] = $connection;
                    $connection->onClose(function () use ($socketId) {
                        unset($this->connections[$socketId]);
                    });

                    // Send pusher:connection_established
                    $activityTimeout = (int) ($this->config['activity_timeout'] ?? 30);
                    $connection->send(Protocol::connectionEstablished($socketId, $activityTimeout));

                    if (strlen($leftover) > 0) {
                        $connection->onData($leftover);
                    }

                    return;
                }

                // Branch: HTTP API
                if (strtolower($parsedRequest->getHeaderLine('Expect')) === '100-continue') {
                    $conn->write("HTTP/1.1 100 Continue\r\n\r\n");
                }

                $contentLength = $parsedRequest->getHeaderLine('Content-Length');
                $expectedBodyLength = $contentLength !== '' ? (int) $contentLength : 0;
                $bodyBuffer = $leftover;
            } else {
                $bodyBuffer .= $chunk;
            }

            // Once the full body has been buffered, process HTTP API request
            if (strlen($bodyBuffer) >= $expectedBodyLength) {
                $rawBody = substr($bodyBuffer, 0, $expectedBodyLength);
                $method = $parsedRequest->getMethod();
                $path = $parsedRequest->getUri()->getPath();
                $query = $parsedRequest->getUri()->getQuery();
                $maxReqSize = (int) ($this->config['max_request_size'] ?? 100000);

                [$statusCode, $headers, $responseBody] = $this->api->handle(
                    $method,
                    $path,
                    $query,
                    $rawBody,
                    $maxReqSize
                );

                $headers['Connection'] = 'close';
                $this->writeRawResponse($conn, $statusCode, $headers, $responseBody);
                $conn->end();
            }
        });
    }

    private function completeUpgradeAndClose(
        SocketConnectionInterface $conn,
        RequestInterface $request,
        int $errorCode,
        string $errorMessage
    ): void {
        $handshake = $this->negotiator->handshake($request);
        $conn->write(Message::toString($handshake));

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
        if ($origin === '') {
            return false;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $hostWithPort = $port ? "{$host}:{$port}" : $host;

        foreach ($allowed as $pattern) {
            if ($pattern === $origin || $pattern === $host || $pattern === $hostWithPort || fnmatch($pattern, $origin) || fnmatch($pattern, (string) $host)) {
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
            413 => 'Payload Too Large',
            500 => 'Internal Server Error',
        ];
        $phrase = $statusTexts[$statusCode] ?? 'Unknown';

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
            if ($this->debug) {
                echo sprintf('[Relay Memory Guard] Memory (%.2f MB) exceeded limit (%d MB). Restarting...', $usedMb, $limitMb) . PHP_EOL;
            }
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
            $this->loop->addSignal(SIGTERM, $handler);
        }
        if (defined('SIGINT')) {
            $this->loop->addSignal(SIGINT, $handler);
        }
    }
}
