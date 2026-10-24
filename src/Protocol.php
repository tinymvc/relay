<?php

namespace Spark\Relay;

use function count;
use function is_string;
use function json_encode;
use function preg_match;
use function random_int;
use function strlen;
use function str_starts_with;

class Protocol
{
    // Pusher close codes
    public const CLOSE_APPLICATION_UNKNOWN = 4001;
    public const CLOSE_APPLICATION_DISABLED = 4003;
    public const CLOSE_OVER_CONNECTION_LIMIT = 4004;
    public const CLOSE_APPLICATION_UNAUTHORIZED = 4009;
    public const CLOSE_SERVER_SHUTDOWN = 4200;
    public const CLOSE_PONG_TIMEOUT = 4201;
    public const CLOSE_INACTIVITY = 4202;
    public const CLOSE_MESSAGE_TOO_BIG = 1009;

    // Pusher limits
    public const MAX_EVENT_DATA_SIZE = 10240;
    public const MAX_CHANNELS_PER_EVENT = 100;
    public const MAX_EVENT_NAME_LENGTH = 200;
    public const MAX_CHANNEL_NAME_LENGTH = 164;
    public const MAX_BATCH_SIZE = 10;
    public const MAX_TIMESTAMP_SKEW = 600;

    /**
     * Build a Pusher protocol frame.
     * 
     * If $data is already a string, it is used verbatim (never double-encoded).
     * If $data is an array/object, it is JSON-encoded into a string.
     */
    public static function frame(string $event, mixed $data, ?string $channel = null): string
    {
        $payload = ['event' => $event];

        if ($channel !== null) {
            $payload['channel'] = $channel;
        }

        if (is_string($data)) {
            $payload['data'] = $data;
        } else {
            $payload['data'] = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function connectionEstablished(string $socketId, int $activityTimeout): string
    {
        return self::frame('pusher:connection_established', [
            'socket_id' => $socketId,
            'activity_timeout' => $activityTimeout,
        ]);
    }

    public static function error(?int $code, string $message): string
    {
        $data = ['message' => $message];
        if ($code !== null) {
            $data['code'] = $code;
        }

        return self::frame('pusher:error', $data);
    }

    public static function ping(): string
    {
        return self::frame('pusher:ping', '{}');
    }

    public static function pong(): string
    {
        return self::frame('pusher:pong', '{}');
    }

    public static function subscriptionSucceeded(string $channel, ?array $presence = null): string
    {
        if ($presence === null) {
            return self::frame('pusher_internal:subscription_succeeded', '{}', $channel);
        }

        $hash = $presence['hash'] ?? [];
        $ids = array_values($presence['ids'] ?? []);

        return self::frame('pusher_internal:subscription_succeeded', [
            'presence' => [
                'count' => $presence['count'] ?? count($ids),
                'ids' => $ids,
                'hash' => (object) $hash,
            ],
        ], $channel);
    }

    public static function memberAdded(string $channel, string $userId, mixed $userInfo = null): string
    {
        $data = ['user_id' => $userId];
        if ($userInfo !== null) {
            $data['user_info'] = $userInfo;
        }

        return self::frame('pusher_internal:member_added', $data, $channel);
    }

    public static function memberRemoved(string $channel, string $userId): string
    {
        return self::frame('pusher_internal:member_removed', [
            'user_id' => $userId,
        ], $channel);
    }

    public static function validChannel(string $name): bool
    {
        $len = strlen($name);
        if ($len === 0 || $len > self::MAX_CHANNEL_NAME_LENGTH) {
            return false;
        }

        return preg_match('/\A[A-Za-z0-9_\-=@,.;]+\z/', $name) === 1;
    }

    public static function isCacheChannel(string $name): bool
    {
        foreach (['cache-', 'private-cache-', 'presence-cache-', 'private-encrypted-cache-'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public static function channelType(string $name): string
    {
        if (str_starts_with($name, 'presence-')) {
            return 'presence';
        }

        if (str_starts_with($name, 'private-')) {
            return 'private';
        }

        return 'public';
    }

    public static function validSocketId(string $socketId): bool
    {
        return preg_match('/\A[0-9]+\.[0-9]+\z/', $socketId) === 1;
    }

    public static function newSocketId(?callable $exists = null): string
    {
        do {
            $id = random_int(1, 1000000000) . '.' . random_int(1, 1000000000);
        } while ($exists !== null && $exists($id));

        return $id;
    }

    public static function validEventName(string $name): bool
    {
        $len = strlen($name);
        return $len > 0 && $len <= self::MAX_EVENT_NAME_LENGTH;
    }

    public static function isClientEvent(string $name): bool
    {
        return str_starts_with($name, 'client-');
    }
}
