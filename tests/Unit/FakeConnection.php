<?php

namespace Tests\Unit;

use RuntimeException;
use Spark\Relay\App;
use Spark\Relay\ConnectionInterface;

class FakeConnection implements ConnectionInterface
{
    /** @var array<string> */
    public array $sent = [];

    /** @var array<array{code: int, reason: string}> */
    public array $closed = [];

    public bool $shouldThrowOnSend = false;

    public function __construct(
        public readonly string $socketId,
        public readonly App $app
    ) {
    }

    public function getSocketId(): string
    {
        return $this->socketId;
    }

    public function getApp(): App
    {
        return $this->app;
    }

    public function send(string $frame): void
    {
        if ($this->shouldThrowOnSend) {
            throw new RuntimeException('Simulated send failure');
        }

        $this->sent[] = $frame;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->closed[] = ['code' => $code, 'reason' => $reason];
    }

    public function lastSent(): ?string
    {
        return $this->sent !== [] ? $this->sent[array_key_last($this->sent)] : null;
    }

    public function lastSentDecoded(): ?array
    {
        $last = $this->lastSent();
        return $last !== null ? json_decode($last, true) : null;
    }
}
