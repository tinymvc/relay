<?php

namespace Spark\Relay;

/** App-isolated process-lifetime counters; payloads and credentials are never retained. */
class Metrics
{
    private array $apps = [];
    private readonly float $startedAt;

    private const EMPTY_COUNTERS = [
        'connections' => 0,
        'connections_total' => 0,
        'messages_received' => 0,
        'messages_sent' => 0,
        'bytes_received' => 0,
        'bytes_sent' => 0,
    ];

    public function __construct()
    {
        $this->startedAt = hrtime(true) / 1e9;
    }

    public function connected(string $appId): void
    {
        $this->apps[$appId] ??= self::EMPTY_COUNTERS;
        $this->apps[$appId]['connections']++;
        $this->apps[$appId]['connections_total']++;
    }

    public function disconnected(string $appId): void
    {
        if (($this->apps[$appId]['connections'] ?? 0) > 0) {
            $this->apps[$appId]['connections']--;
        }
    }

    public function received(string $appId, int $bytes): void
    {
        $this->apps[$appId] ??= self::EMPTY_COUNTERS;
        $this->apps[$appId]['messages_received']++;
        $this->apps[$appId]['bytes_received'] += $bytes;
    }

    public function sent(string $appId, int $bytes): void
    {
        $this->apps[$appId] ??= self::EMPTY_COUNTERS;
        $this->apps[$appId]['messages_sent']++;
        $this->apps[$appId]['bytes_sent'] += $bytes;
    }

    public function snapshot(string $appId): array
    {
        return [
            'uptime_seconds' => (int) (hrtime(true) / 1e9 - $this->startedAt),
            ...($this->apps[$appId] ?? self::EMPTY_COUNTERS),
        ];
    }
}
