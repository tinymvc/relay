<?php

namespace Spark\Relay;

use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use LengthException;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface as SocketConnectionInterface;
use Throwable;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function pack;
use function strlen;

class Connection implements ConnectionInterface
{
    private MessageBuffer $messageBuffer;
    private float $lastActivity;
    private ?float $pingSentAt = null;
    private bool $closed = false;
    private int $bufferedBytes = 0;
    private ?TimerInterface $closeTimer = null;
    private float $clientEventTokens;
    private float $clientEventRefill;
    /** @var array<callable(self): void> */
    private array $closeCallbacks = [];

    public function __construct(
        private readonly SocketConnectionInterface $stream,
        public readonly string $socketId,
        public readonly App $app,
        private readonly Channels $channels,
        public readonly array $queryParams = [],
        private readonly bool $debug = false,
        private readonly int $maxOutboundBufferSize = 1048576,
        private ?LoopInterface $loop = null,
        private readonly ?Metrics $metrics = null,
    ) {
        $this->lastActivity = microtime(true);
        $this->loop ??= Loop::get();
        $this->clientEventTokens = $app->clientEventRate;
        $this->clientEventRefill = $this->lastActivity;

        $this->messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            $this->handleInboundMessage(...),
            $this->handleControlFrame(...),
            true,
            maxMessagePayloadSize: $app->maxMessageSize,
            maxFramePayloadSize: $app->maxMessageSize,
        );

        $this->stream->on('close', function () {
            if ($this->closeTimer !== null) {
                $this->loop->cancelTimer($this->closeTimer);
                $this->closeTimer = null;
            }
            $this->close(1000, 'Stream closed');
        });
        $this->stream->on('error', function () {
            $this->close(1011, 'Stream error');
            $this->stream->close();
        });
        $this->stream->on('drain', function () {
            $this->bufferedBytes = 0;
        });
    }

    public function getSocketId(): string
    {
        return $this->socketId;
    }

    public function getApp(): App
    {
        return $this->app;
    }

    public function getLastActivity(): float
    {
        return $this->lastActivity;
    }

    public function touch(): void
    {
        $this->lastActivity = microtime(true);
        $this->pingSentAt = null;
    }

    public function getPingSentAt(): ?float
    {
        return $this->pingSentAt;
    }

    public function setPingSentAt(?float $time): void
    {
        $this->pingSentAt = $time;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(callable $callback): void
    {
        $this->closeCallbacks[] = $callback;
    }

    /**
     * Feed incoming raw socket bytes to the MessageBuffer.
     */
    public function onData(string $chunk): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->messageBuffer->onData($chunk);
        } catch (LengthException) {
            $this->close(1009, 'Too many message fragments');
        } catch (Throwable $e) {
            if ($this->debug) {
                echo "[Relay Debug] MessageBuffer error on socket {$this->socketId}: " . $e->getMessage() . PHP_EOL;
            }
            $this->close(1002, 'Protocol error');
        }
    }

    public function send(string $frame): void
    {
        if ($this->closed || !$this->stream->isWritable()) {
            return;
        }

        if ($this->debug) {
            echo "[Relay Out -> {$this->socketId}] " . strlen($frame) . ' bytes' . PHP_EOL;
        }

        $f = new Frame($frame, true, Frame::OP_TEXT);
        $this->sendFrame($f);
    }

    public function sendFrame(Frame $frame): void
    {
        if ($this->closed || !$this->stream->isWritable()) {
            return;
        }

        $bytes = $frame->getContents();
        if ($this->bufferedBytes + strlen($bytes) > $this->maxOutboundBufferSize) {
            $this->close(1001, 'Slow consumer');
            $this->stream->close();
            return;
        }

        $writable = $this->stream->write($bytes);
        if ($frame->getOpcode() === Frame::OP_TEXT) {
            $this->metrics?->sent($this->app->appId, strlen($frame->getPayload()));
        }
        if (!$writable || $this->bufferedBytes > 0) {
            $this->bufferedBytes += strlen($bytes);
        }
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->debug) {
            echo "[Relay Close -> {$this->socketId}] Code: {$code}, Reason: {$reason}" . PHP_EOL;
        }

        $this->stream->pause();
        $this->channels->disconnect($this);

        if ($this->stream->isWritable()) {
            try {
                // Force termination even if a peer never drains its socket.
                $this->closeTimer = $this->loop->addTimer(2.0, function () {
                    $this->closeTimer = null;
                    $this->stream->close();
                });
                $closePayload = pack('n', $code) . $reason;
                $frame = new Frame($closePayload, true, Frame::OP_CLOSE);
                $this->stream->write($frame->getContents());
                $this->stream->end();
            } catch (Throwable) {
                $this->stream->close();
            }
        } else {
            $this->stream->close();
        }

        foreach ($this->closeCallbacks as $cb) {
            try {
                $cb($this);
            } catch (Throwable) {
            }
        }
        $this->closeCallbacks = [];
    }

    private function handleInboundMessage($message): void
    {
        if ($this->closed) {
            return;
        }
        if ($message->isBinary()) {
            $this->close(1003, 'Text messages required');
            return;
        }

        $payload = $message->getPayload();

        // Enforce maximum inbound message payload
        if (strlen($payload) > $this->app->maxMessageSize) {
            $this->close(Protocol::CLOSE_MESSAGE_TOO_BIG, 'Message too big');
            return;
        }

        $this->touch();
        $this->metrics?->received($this->app->appId, strlen($payload));

        if ($this->debug) {
            echo "[Relay In <- {$this->socketId}] " . strlen($payload) . ' bytes' . PHP_EOL;
        }

        $decoded = json_decode($payload, true);
        if (
            !is_array($decoded) || !is_string($decoded['event'] ?? null)
            || !Protocol::validEventName($decoded['event'])
        ) {
            $this->send(Protocol::error(null, 'Invalid JSON or missing event'));
            return;
        }

        $event = (string) $decoded['event'];

        if ($event === 'pusher:ping') {
            $this->send(Protocol::pong());
            return;
        }

        if ($event === 'pusher:pong') {
            // Keep-alive activity touch only
            return;
        }

        if ($event === 'pusher:subscribe') {
            $this->handleSubscribe($decoded['data'] ?? []);
            return;
        }

        if ($event === 'pusher:unsubscribe') {
            $this->handleUnsubscribe($decoded['data'] ?? []);
            return;
        }

        if (Protocol::isClientEvent($event)) {
            $channel = $decoded['channel'] ?? null;
            if (!is_string($channel) || !Protocol::validChannel($channel)) {
                $this->send(Protocol::error(null, 'Invalid channel name'));
                return;
            }
            $now = microtime(true);
            $this->clientEventTokens = min(
                $this->app->clientEventRate,
                $this->clientEventTokens + ($now - $this->clientEventRefill) * $this->app->clientEventRate
            );
            $this->clientEventRefill = $now;
            if ($this->clientEventTokens < 1) {
                $this->send(Protocol::error(4301, 'Client event rate limit exceeded'));
                return;
            }
            $this->clientEventTokens--;
            $data = $decoded['data'] ?? '';
            $dataString = is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->channels->whisper($this, $channel, $event, $dataString);
            return;
        }

        // Unrecognized events are silently ignored per Pusher protocol
    }

    private function handleSubscribe(mixed $data): void
    {
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }

        if (!is_array($data) || !is_string($data['channel'] ?? null)) {
            $this->send(Protocol::error(null, 'Missing channel in subscribe data'));
            return;
        }

        $channel = (string) $data['channel'];
        if (!Protocol::validChannel($channel)) {
            $this->send(Protocol::error(null, 'Invalid channel name'));
            return;
        }

        $type = Protocol::channelType($channel);
        $channelData = null;

        if ($type === 'private' || $type === 'presence') {
            $auth = $data['auth'] ?? '';
            $channelData = $data['channel_data'] ?? null;

            if (
                !is_string($auth) || ($channelData !== null && !is_string($channelData))
                || !Signature::verifyChannelAuth($this->app, $auth, $this->socketId, $channel, $channelData)
            ) {
                // Send 4009 error; do not subscribe, keep socket open
                $this->send(Protocol::error(Protocol::CLOSE_APPLICATION_UNAUTHORIZED, 'Invalid auth signature'));
                return;
            }
        }

        if (!$this->channels->subscribe($this, $channel, $channelData)) {
            $this->send(Protocol::error(null, 'Subscription rejected: invalid presence data or channel limit reached'));
        }
    }

    private function handleUnsubscribe(mixed $data): void
    {
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }

        if (!is_array($data) || !is_string($data['channel'] ?? null)) {
            return;
        }

        $channel = (string) $data['channel'];
        $this->channels->unsubscribe($this, $channel);
    }

    private function handleControlFrame(Frame $frame): void
    {
        if ($this->closed) {
            return;
        }
        $opcode = $frame->getOpcode();

        if ($opcode === Frame::OP_PING) {
            $this->touch();
            $pong = new Frame($frame->getPayload(), true, Frame::OP_PONG);
            $this->sendFrame($pong);
        } elseif ($opcode === Frame::OP_PONG) {
            $this->touch();
        } elseif ($opcode === Frame::OP_CLOSE) {
            $payload = $frame->getPayload();
            $code = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 1000;
            $this->close($code, substr($payload, 2));
        }
    }
}
