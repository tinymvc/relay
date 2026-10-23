<?php

namespace Spark\Relay;

interface ConnectionInterface
{
    public function getSocketId(): string;

    public function getApp(): App;

    public function send(string $frame): void;

    public function close(int $code = 1000, string $reason = ''): void;
}
