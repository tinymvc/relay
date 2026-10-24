<?php

namespace Tests\Unit;

use React\Socket\Connection;
use React\Stream\WritableStreamInterface;

class FakeStream extends Connection
{
    public array $writes = [];
    public bool $writable = true;
    public bool $backpressure = false;
    public bool $closed = false;

    public function __construct() {}
    public function getRemoteAddress(): ?string { return 'tcp://127.0.0.1:12345'; }
    public function getLocalAddress(): ?string { return 'tcp://127.0.0.1:8080'; }
    public function isReadable(): bool { return !$this->closed; }
    public function isWritable(): bool { return $this->writable && !$this->closed; }
    public function pause(): void {}
    public function resume(): void {}
    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface { return $dest; }

    public function write($data): bool
    {
        $this->writes[] = $data;
        return !$this->backpressure;
    }

    public function end($data = null): void
    {
        if ($data !== null) {
            $this->write($data);
        }
        if (!$this->backpressure) {
            $this->close();
        }
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->emit('close');
        }
    }
}
