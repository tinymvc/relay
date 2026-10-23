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
        public readonly int $maxConnections = 0
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
            maxConnections: (int) ($config['max_connections'] ?? 0)
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
        foreach ($appsConfig as $config) {
            $app = self::fromConfig($config);
            $apps[$app->appId] = $app;
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
