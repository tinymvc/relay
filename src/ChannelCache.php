<?php

namespace Spark\Relay;

use Closure;

/** Bounded, process-local last-event cache. Only backend-published events enter it. */
class ChannelCache
{
    private array $entries = [];
    private array $bytes = [];
    private readonly Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function put(App $app, string $channel, string $frame): void
    {
        $id = $app->appId;
        $this->prune($id);
        $this->forget($id, $channel);
        $size = strlen($frame);
        if ($size > $app->maxCacheSize) {
            return;
        }

        // Insertion order is last-publish order; evict the oldest retained event first.
        while (count($this->entries[$id] ?? []) >= $app->maxCachedChannels
            || ($this->bytes[$id] ?? 0) + $size > $app->maxCacheSize) {
            $this->forget($id, array_key_first($this->entries[$id]));
        }

        $this->entries[$id][$channel] = [
            'frame' => $frame,
            'expires' => ($this->clock)() + $app->cacheTtl,
        ];
        $this->bytes[$id] = ($this->bytes[$id] ?? 0) + $size;
    }

    public function get(string $appId, string $channel): ?string
    {
        $entry = $this->entries[$appId][$channel] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry['expires'] <= ($this->clock)()) {
            $this->forget($appId, $channel);
            return null;
        }
        return $entry['frame'];
    }

    public function prune(?string $appId = null): void
    {
        $now = ($this->clock)();
        foreach ($appId === null ? array_keys($this->entries) : [$appId] as $id) {
            foreach ($this->entries[$id] ?? [] as $channel => $entry) {
                // TTL is fixed per configured app, so expiration follows insertion order.
                if ($entry['expires'] > $now) {
                    break;
                }
                $this->forget((string) $id, $channel);
            }
        }
    }

    public function statistics(string $appId): array
    {
        $this->prune($appId);
        return [
            'cached_channels' => count($this->entries[$appId] ?? []),
            'cache_bytes' => $this->bytes[$appId] ?? 0,
        ];
    }

    private function forget(string $appId, string $channel): void
    {
        if (!isset($this->entries[$appId][$channel])) {
            return;
        }
        $this->bytes[$appId] -= strlen($this->entries[$appId][$channel]['frame']);
        unset($this->entries[$appId][$channel]);
        if ($this->entries[$appId] === []) {
            unset($this->entries[$appId], $this->bytes[$appId]);
        }
    }
}
