<?php

namespace Spark\Relay;

use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
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
    /** @var array<callable(self): void> */
    private array $closeCallbacks = [];

    public function __construct(
        private readonly SocketConnectionInterface $stream,
        public readonly string $socketId,
        public readonly App $app,
        private readonly Channels $channels,
        public readonly array $queryParams = [],
        private readonly bool $debug = false
    ) {
        $this->lastActivity = microtime(true);

        $prev = error_reporting(error_reporting() & ~E_DEPRECATED);
        $this->messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            $this->handleInboundMessage(...),
            $this->handleControlFrame(...),
            true // expect masked frames from client
        );

        error_reporting($prev);

        $this->stream->on('close', function () {
            $this->close(1000, 'Stream closed');
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
            echo "[Relay Frame Out -> {$this->socketId}] {$frame}" . PHP_EOL;
        }

        $f = new Frame($frame, true, Frame::OP_TEXT);
        $this->stream->write($f->getContents());
    }

    public function sendFrame(Frame $frame): void
    {
        if ($this->closed || !$this->stream->isWritable()) {
            return;
        }

        $this->stream->write($frame->getContents());
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

        // Idempotent channel disconnect
        $this->channels->disconnect($this);

        if ($this->stream->isWritable()) {
            try {
                $closePayload = pack('n', $code) . $reason;
                $frame = new Frame($closePayload, true, Frame::OP_CLOSE);
                $this->stream->write($frame->getContents());
                $this->stream->end();
            } catch (Throwable) {
                // Ignore write errors during socket termination
            }
        }

        foreach ($this->closeCallbacks as $cb) {
            try {
                $cb($this);
            } catch (Throwable) {
            }
        }
    }

    private function handleInboundMessage($message): void
    {
        $payload = $message->getPayload();

        // Enforce maximum inbound message payload
        if (strlen($payload) > $this->app->maxMessageSize) {
            $this->close(Protocol::CLOSE_MESSAGE_TOO_BIG, 'Message too big');
            return;
        }

        $this->touch();

        if ($this->debug) {
            // Redact auth tokens from debug output
            $loggedPayload = preg_replace('/"auth"\s*:\s*"[^"]*"/', '"auth":"[REDACTED]"', $payload);
            echo "[Relay Frame In <- {$this->socketId}] {$loggedPayload}" . PHP_EOL;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !isset($decoded['event'])) {
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
            $channel = (string) ($decoded['channel'] ?? '');
            $data = $decoded['data'] ?? '';
            $dataString = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

        if (!is_array($data) || empty($data['channel'])) {
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
            $auth = (string) ($data['auth'] ?? '');
            $channelData = $data['channel_data'] ?? null;
            if (is_array($channelData)) {
                $channelData = json_encode($channelData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            if (!Signature::verifyChannelAuth($this->app, $auth, $this->socketId, $channel, $channelData)) {
                // Send 4009 error; do not subscribe, keep socket open
                $this->send(Protocol::error(Protocol::CLOSE_APPLICATION_UNAUTHORIZED, 'Invalid auth signature'));
                return;
            }
        }

        $this->channels->subscribe($this, $channel, $channelData);
    }

    private function handleUnsubscribe(mixed $data): void
    {
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }

        if (!is_array($data) || empty($data['channel'])) {
            return;
        }

        $channel = (string) $data['channel'];
        $this->channels->unsubscribe($this, $channel);
    }

    private function handleControlFrame(Frame $frame): void
    {
        $opcode = $frame->getOpcode();

        if ($opcode === Frame::OP_PING) {
            $pong = new Frame($frame->getPayload(), true, Frame::OP_PONG);
            $this->sendFrame($pong);
        } elseif ($opcode === Frame::OP_CLOSE) {
            $close = new Frame($frame->getPayload(), true, Frame::OP_CLOSE);
            $this->sendFrame($close);
            $this->close(1000, 'Client closed');
        }
    }
}
