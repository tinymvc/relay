<?php

namespace Spark\Relay;

use InvalidArgumentException;
use function is_array;

class App
{
    /**
     * @param string $appId
     * @param string $key
     * @param string $secret
     * @param array<string> $allowedOrigins
     * @param bool $clientEvents
     * @param int $maxMessageSize
     * @param int $maxConnections
     */
    public function __construct(
        public readonly string $appId,
        public readonly string $key,
        public readonly string $secret,
        public readonly array $allowedOrigins = ['*'],
        public readonly bool $clientEvents = false,
        public readonly int $maxMessageSize = 10000,
        public readonly int $maxConnections = 0,
        public readonly int $maxChannelsPerConnection = 100,
        public readonly int $maxPresenceMembers = 100,
        public readonly int $clientEventRate = 10,
        public readonly int $cacheTtl = 1800,
        public readonly int $maxCachedChannels = 1000,
        public readonly int $maxCacheSize = 10485760,
    ) {
        if ($this->appId === '') {
            throw new InvalidArgumentException('Relay app missing required app_id.');
        }

        if ($this->key === '') {
            throw new InvalidArgumentException('Relay app missing required key.');
        }

        if ($this->secret === '') {
            throw new InvalidArgumentException('Relay app missing required secret.');
        }

        if (
            $maxMessageSize < 1 || $maxConnections < 0 || $maxChannelsPerConnection < 1
            || $maxPresenceMembers < 1 || $clientEventRate < 1
            || $cacheTtl < 1 || $maxCachedChannels < 1 || $maxCacheSize < 1
        ) {
            throw new InvalidArgumentException('Relay limits must be positive (max_connections may be zero).');
        }

        foreach ($allowedOrigins as $origin) {
            if (!is_string($origin) || $origin === '') {
                throw new InvalidArgumentException('Relay allowed_origins must contain non-empty strings.');
            }
        }
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            appId: (string) ($config['app_id'] ?? ''),
            key: (string) ($config['key'] ?? ''),
            secret: (string) ($config['secret'] ?? ''),
            allowedOrigins: (array) ($config['allowed_origins'] ?? ['*']),
            clientEvents: (bool) ($config['client_events'] ?? false),
            maxMessageSize: (int) ($config['max_message_size'] ?? 10000),
            maxConnections: (int) ($config['max_connections'] ?? 0),
            maxChannelsPerConnection: (int) ($config['max_channels_per_connection'] ?? 100),
            maxPresenceMembers: (int) ($config['max_presence_members'] ?? 100),
            clientEventRate: (int) ($config['client_event_rate'] ?? 10),
            cacheTtl: (int) ($config['cache_ttl'] ?? 1800),
            maxCachedChannels: (int) ($config['max_cached_channels'] ?? 1000),
            maxCacheSize: (int) ($config['max_cache_size'] ?? 10485760),
        );
    }

    /**
     * Load all apps from configuration array.
     *
     * @param array $appsConfig
     * @return array<string, self>
     */
    public static function loadAll(array $appsConfig): array
    {
        $apps = [];
        $keys = [];
        foreach ($appsConfig as $config) {
            if (!is_array($config)) {
                throw new InvalidArgumentException('Each Relay app configuration must be an array.');
            }

            $app = self::fromConfig($config);
            if (isset($apps[$app->appId]) || isset($keys[$app->key])) {
                throw new InvalidArgumentException('Relay app IDs and keys must be unique.');
            }

            $apps[$app->appId] = $app;
            $keys[$app->key] = true;
        }

        return $apps;
    }

    /**
     * Find app by key.
     */
    public static function byKey(string $key, ?array $apps = null): ?self
    {
        $apps ??= self::configuredApps();

        foreach ($apps as $app) {
            if ($app->key === $key) {
                return $app;
            }
        }

        return null;
    }

    /**
     * Find app by app id.
     */
    public static function byId(string $id, ?array $apps = null): ?self
    {
        $apps ??= self::configuredApps();

        return $apps[$id] ?? null;
    }

    /**
     * @return array<string, self>
     */
    private static function configuredApps(): array
    {
        $raw = function_exists('config') ? config('relay.apps', []) : [];
        return self::loadAll(is_array($raw) ? $raw : []);
    }
}
