<?php

namespace Spark\Relay;

use Throwable;
use function array_keys;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function str_starts_with;

class Channels
{
    /**
     * Channels registry structured by appId -> channelName -> details.
     * 
     * @var array<string, array<string, array{
     *     type: string,
     *     connections: array<string, ConnectionInterface>,
     *     users: array<string, array{info: mixed, sockets: array<string, bool>}>,
     *     socket_users: array<string, string>
     * }>>
     */
    private array $channels = [];

    /**
     * Subscriptions registry: appId -> socketId -> array<channelName, bool>.
     * 
     * @var array<string, array<string, array<string, bool>>>
     */
    private array $subscriptions = [];

    /**
     * Subscribe a connection to a channel.
     * Note: Server verifies private/presence signatures before calling this.
     */
    public function subscribe(ConnectionInterface $connection, string $channel, ?string $channelData = null): bool
    {
        if (!Protocol::validChannel($channel)) {
            return false;
        }

        $appId = $connection->getApp()->appId;
        $socketId = $connection->getSocketId();
        $type = Protocol::channelType($channel);

        if (!isset($this->channels[$appId][$channel])) {
            $this->channels[$appId][$channel] = [
                'type' => $type,
                'connections' => [],
                'users' => [],
                'socket_users' => [],
            ];
        }

        $isAlreadySubscribed = isset($this->channels[$appId][$channel]['connections'][$socketId]);
        $this->channels[$appId][$channel]['connections'][$socketId] = $connection;
        $this->subscriptions[$appId][$socketId][$channel] = true;

        if ($type === 'presence') {
            $decoded = json_decode((string) $channelData, true);
            if (!is_array($decoded) || !isset($decoded['user_id'])) {
                return false;
            }

            $userId = (string) $decoded['user_id'];
            $userInfo = $decoded['user_info'] ?? null;

            $this->channels[$appId][$channel]['socket_users'][$socketId] = $userId;
            $isFirstSocketForUser = !isset($this->channels[$appId][$channel]['users'][$userId]);

            if ($isFirstSocketForUser) {
                $this->channels[$appId][$channel]['users'][$userId] = [
                    'info' => $userInfo,
                    'sockets' => [$socketId => true],
                ];
            } else {
                $this->channels[$appId][$channel]['users'][$userId]['sockets'][$socketId] = true;
            }

            // Member added is emitted only for the first socket of this user and not on idempotent re-subscribe
            if ($isFirstSocketForUser && !$isAlreadySubscribed) {
                $memberAdded = Protocol::memberAdded($channel, $userId, $userInfo);
                $this->publishRaw($appId, $channel, $memberAdded, exceptSocketId: $socketId);
            }

            // Reply subscription_succeeded with presence roster
            $ids = array_keys($this->channels[$appId][$channel]['users']);
            $hash = [];
            foreach ($this->channels[$appId][$channel]['users'] as $uId => $userRecord) {
                $hash[$uId] = $userRecord['info'];
            }

            $successFrame = Protocol::subscriptionSucceeded($channel, [
                'count' => count($ids),
                'ids' => $ids,
                'hash' => (object) $hash,
            ]);

            $connection->send($successFrame);

            return true;
        }

        // Public or private channel
        $successFrame = Protocol::subscriptionSucceeded($channel);
        $connection->send($successFrame);

        return true;
    }

    /**
     * Unsubscribe a connection from a channel.
     */
    public function unsubscribe(ConnectionInterface $connection, string $channel): bool
    {
        $appId = $connection->getApp()->appId;
        $socketId = $connection->getSocketId();

        if (!isset($this->channels[$appId][$channel]['connections'][$socketId])) {
            return false;
        }

        unset($this->channels[$appId][$channel]['connections'][$socketId]);
        unset($this->subscriptions[$appId][$socketId][$channel]);

        if (empty($this->subscriptions[$appId][$socketId])) {
            unset($this->subscriptions[$appId][$socketId]);
        }

        // Handle presence cleanup
        if ($this->channels[$appId][$channel]['type'] === 'presence') {
            $userId = $this->channels[$appId][$channel]['socket_users'][$socketId] ?? null;
            if ($userId !== null) {
                unset($this->channels[$appId][$channel]['socket_users'][$socketId]);

                if (isset($this->channels[$appId][$channel]['users'][$userId])) {
                    unset($this->channels[$appId][$channel]['users'][$userId]['sockets'][$socketId]);

                    if (empty($this->channels[$appId][$channel]['users'][$userId]['sockets'])) {
                        unset($this->channels[$appId][$channel]['users'][$userId]);

                        $memberRemoved = Protocol::memberRemoved($channel, $userId);
                        $this->publishRaw($appId, $channel, $memberRemoved, exceptSocketId: $socketId);
                    }
                }
            }
        }

        // Delete empty channel (no memory leak)
        if (empty($this->channels[$appId][$channel]['connections'])) {
            unset($this->channels[$appId][$channel]);
            if (empty($this->channels[$appId])) {
                unset($this->channels[$appId]);
            }
        }

        return true;
    }

    /**
     * Clean up all subscriptions for a disconnecting connection in O(subscriptions).
     */
    public function disconnect(ConnectionInterface $connection): void
    {
        $appId = $connection->getApp()->appId;
        $socketId = $connection->getSocketId();

        $channels = array_keys($this->subscriptions[$appId][$socketId] ?? []);
        foreach ($channels as $channel) {
            $this->unsubscribe($connection, $channel);
        }

        unset($this->subscriptions[$appId][$socketId]);
        if (empty($this->subscriptions[$appId])) {
            unset($this->subscriptions[$appId]);
        }
    }

    /**
     * Publish an event to one or more channels.
     */
    public function publish(
        App $app,
        array|string $channels,
        string $event,
        string $data,
        ?string $exceptSocketId = null
    ): void {
        $channels = (array) $channels;

        foreach ($channels as $channel) {
            if (!$this->occupied($app->appId, $channel)) {
                continue;
            }

            $frame = Protocol::frame($event, $data, $channel);
            $this->publishRaw($app->appId, $channel, $frame, $exceptSocketId);
        }
    }

    /**
     * Publish raw frame to a channel with snapshot iteration and exception safety.
     */
    public function publishRaw(
        string $appId,
        string $channel,
        string $rawFrame,
        ?string $exceptSocketId = null
    ): void {
        if (!isset($this->channels[$appId][$channel]['connections'])) {
            return;
        }

        // Take snapshot to protect against modification during iteration
        $snapshot = $this->channels[$appId][$channel]['connections'];
        foreach ($snapshot as $sId => $connection) {
            if ($exceptSocketId !== null && $sId === $exceptSocketId) {
                continue;
            }

            try {
                $connection->send($rawFrame);
            } catch (Throwable) {
                // Sockets that throw on write must not break delivery to peers
            }
        }
    }

    /**
     * Handle client whisper event.
     */
    public function whisper(ConnectionInterface $connection, string $channel, string $event, string $data): bool
    {
        $app = $connection->getApp();

        if (!$app->clientEvents) {
            return false;
        }

        if (!Protocol::isClientEvent($event)) {
            return false;
        }

        $appId = $app->appId;
        $socketId = $connection->getSocketId();

        // Must be subscribed to the channel
        if (!isset($this->channels[$appId][$channel]['connections'][$socketId])) {
            return false;
        }

        $type = $this->channels[$appId][$channel]['type'];
        // Whispers allowed only on private or presence channels
        if ($type !== 'private' && $type !== 'presence') {
            return false;
        }

        if ($type === 'presence') {
            $userId = $this->channels[$appId][$channel]['socket_users'][$socketId] ?? null;
            $frameData = [
                'event' => $event,
                'channel' => $channel,
                'data' => $data,
            ];
            if ($userId !== null) {
                $frameData['user_id'] = $userId;
            }
            $frame = json_encode($frameData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $frame = Protocol::frame($event, $data, $channel);
        }

        $this->publishRaw($appId, $channel, $frame, exceptSocketId: $socketId);

        return true;
    }

    public function occupied(string $appId, string $channel): bool
    {
        return !empty($this->channels[$appId][$channel]['connections']);
    }

    public function channelInfo(string $appId, string $channel, array $info = []): ?array
    {
        if (!isset($this->channels[$appId][$channel])) {
            return null;
        }

        $ch = $this->channels[$appId][$channel];
        $result = ['occupied' => true];

        if (in_array('user_count', $info, true)) {
            $result['user_count'] = count($ch['users']);
        }

        if (in_array('subscription_count', $info, true)) {
            $result['subscription_count'] = count($ch['connections']);
        }

        return $result;
    }

    public function users(string $appId, string $channel): ?array
    {
        if (!isset($this->channels[$appId][$channel])) {
            return null;
        }

        if ($this->channels[$appId][$channel]['type'] !== 'presence') {
            return null;
        }

        $users = [];
        foreach (array_keys($this->channels[$appId][$channel]['users']) as $userId) {
            $users[] = ['id' => (string) $userId];
        }

        return ['users' => $users];
    }

    public function list(string $appId, ?string $prefix = null, array $info = []): array
    {
        $map = [];

        if (isset($this->channels[$appId])) {
            foreach ($this->channels[$appId] as $name => $ch) {
                if ($prefix !== null && $prefix !== '' && !str_starts_with($name, $prefix)) {
                    continue;
                }

                $item = [];
                if (in_array('user_count', $info, true)) {
                    $item['user_count'] = count($ch['users']);
                }

                $map[$name] = (object) $item;
            }
        }

        return ['channels' => (object) $map];
    }

    public function connectionCount(string $appId): int
    {
        return count($this->subscriptions[$appId] ?? []);
    }

    public function isSubscribed(string $appId, string $channel, string $socketId): bool
    {
        return isset($this->channels[$appId][$channel]['connections'][$socketId]);
    }
}
