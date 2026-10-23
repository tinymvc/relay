<?php

namespace Spark\Relay;

use function count;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function strlen;
use function str_starts_with;

class Api
{
    public function __construct(
        private readonly Channels $channels,
        /** @var array<string, App> */
        private readonly array $apps
    ) {
    }

    /**
     * Handle an incoming HTTP API request.
     *
     * @param string $method
     * @param string $path
     * @param array|string $query
     * @param string $rawBody
     * @param int $maxRequestSize
     * @return array{0: int, 1: array<string, string>, 2: string} [status, headers, body]
     */
    public function handle(
        string $method,
        string $path,
        array|string $query,
        string $rawBody,
        int $maxRequestSize = 100000
    ): array {
        if (strlen($rawBody) > $maxRequestSize) {
            return $this->json(413, ['error' => 'Request body too large']);
        }

        // Match /apps/{app_id}/...
        if (!preg_match('#^/apps/([^/]+)(/.*)?$#', $path, $matches)) {
            return $this->json(404, ['error' => 'Not found']);
        }

        $appId = $matches[1];
        $subPath = $matches[2] ?? '';

        $app = $this->apps[$appId] ?? App::byId($appId, $this->apps);
        if ($app === null) {
            return $this->json(404, ['error' => 'App not found']);
        }

        // Verify HMAC signature
        $signError = Signature::verifyApiRequest($app, $method, $path, $query, $rawBody);
        if ($signError !== null) {
            return $this->json(401, ['error' => $signError]);
        }

        $queryArray = is_array($query) ? $query : [];
        if (is_string($query)) {
            parse_str($query, $queryArray);
        }

        // Route API endpoints
        if ($method === 'POST' && $subPath === '/events') {
            return $this->postEvents($app, $rawBody);
        }

        if ($method === 'POST' && $subPath === '/batch_events') {
            return $this->postBatchEvents($app, $rawBody);
        }

        if ($method === 'GET' && $subPath === '/channels') {
            return $this->getChannels($app, $queryArray);
        }

        if ($method === 'GET' && preg_match('#^/channels/([^/]+)/users$#', $subPath, $chMatches)) {
            return $this->getChannelUsers($app, $chMatches[1]);
        }

        if ($method === 'GET' && preg_match('#^/channels/([^/]+)$#', $subPath, $chMatches)) {
            return $this->getChannelInfo($app, $chMatches[1], $queryArray);
        }

        return $this->json(404, ['error' => 'Endpoint not found']);
    }

    private function postEvents(App $app, string $rawBody): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->json(400, ['error' => 'Invalid JSON payload']);
        }

        $validationError = $this->validateEventItem($payload);
        if ($validationError !== null) {
            return $this->json(400, ['error' => $validationError]);
        }

        $event = (string) $payload['name'];
        $data = (string) $payload['data'];
        $channels = isset($payload['channels']) ? (array) $payload['channels'] : (array) $payload['channel'];
        $socketId = isset($payload['socket_id']) ? (string) $payload['socket_id'] : null;

        $this->channels->publish($app, $channels, $event, $data, $socketId);

        return $this->json(200, (object) []);
    }

    private function postBatchEvents(App $app, string $rawBody): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['batch']) || !is_array($payload['batch'])) {
            return $this->json(400, ['error' => 'Missing batch array']);
        }

        $batch = $payload['batch'];
        if (count($batch) === 0 || count($batch) > Protocol::MAX_BATCH_SIZE) {
            return $this->json(400, ['error' => 'Batch size must be between 1 and ' . Protocol::MAX_BATCH_SIZE]);
        }

        // Validate ALL items before publishing any (atomic rejection)
        foreach ($batch as $index => $item) {
            if (!is_array($item)) {
                return $this->json(400, ['error' => "Invalid batch item at index {$index}"]);
            }

            if (!isset($item['channel']) || !is_string($item['channel'])) {
                return $this->json(400, ['error' => "Batch item at index {$index} must specify a single 'channel' string"]);
            }

            $validationError = $this->validateEventItem($item);
            if ($validationError !== null) {
                return $this->json(400, ['error' => "Batch item {$index}: {$validationError}"]);
            }
        }

        // All items valid, publish all
        foreach ($batch as $item) {
            $event = (string) $item['name'];
            $data = (string) $item['data'];
            $channel = (string) $item['channel'];
            $socketId = isset($item['socket_id']) ? (string) $item['socket_id'] : null;

            $this->channels->publish($app, $channel, $event, $data, $socketId);
        }

        return $this->json(200, (object) []);
    }

    private function validateEventItem(array $item): ?string
    {
        if (empty($item['name']) || !is_string($item['name'])) {
            return 'Missing or invalid event name';
        }

        $name = $item['name'];
        if (!Protocol::validEventName($name)) {
            return 'Event name exceeds maximum length';
        }

        if (str_starts_with($name, 'pusher:') || str_starts_with($name, 'pusher_internal:')) {
            return 'Event name cannot use reserved pusher prefixes';
        }

        if (!isset($item['data']) || !is_string($item['data'])) {
            return 'Event data must be a string';
        }

        if (strlen($item['data']) > Protocol::MAX_EVENT_DATA_SIZE) {
            return 'Event data exceeds maximum size limit of ' . Protocol::MAX_EVENT_DATA_SIZE . ' bytes';
        }

        $channels = [];
        if (isset($item['channels'])) {
            if (!is_array($item['channels']) || count($item['channels']) === 0 || count($item['channels']) > Protocol::MAX_CHANNELS_PER_EVENT) {
                return 'Channels must be an array of 1 to ' . Protocol::MAX_CHANNELS_PER_EVENT . ' channel names';
            }
            $channels = $item['channels'];
        } elseif (isset($item['channel'])) {
            if (!is_string($item['channel']) || $item['channel'] === '') {
                return 'Invalid channel name';
            }
            $channels = [$item['channel']];
        } else {
            return 'Event must specify channel or channels';
        }

        foreach ($channels as $ch) {
            if (!is_string($ch) || !Protocol::validChannel($ch)) {
                return "Invalid channel name '{$ch}'";
            }
        }

        if (isset($item['socket_id'])) {
            if (!is_string($item['socket_id']) || !Protocol::validSocketId($item['socket_id'])) {
                return 'Invalid socket_id format';
            }
        }

        return null;
    }

    private function getChannels(App $app, array $query): array
    {
        $prefix = isset($query['filter_by_prefix']) ? (string) $query['filter_by_prefix'] : null;
        $infoParam = isset($query['info']) ? (string) $query['info'] : '';
        $info = $infoParam !== '' ? explode(',', $infoParam) : [];

        // Pusher protocol rule: info=user_count is only valid with a presence- prefix filter
        if (in_array('user_count', $info, true)) {
            if ($prefix === null || !str_starts_with($prefix, 'presence-')) {
                return $this->json(400, ['error' => "Requested user_count info is only valid with a 'presence-' filter_by_prefix"]);
            }
        }

        $result = $this->channels->list($app->appId, $prefix, $info);
        return $this->json(200, $result);
    }

    private function getChannelInfo(App $app, string $channel, array $query): array
    {
        if (!Protocol::validChannel($channel)) {
            return $this->json(400, ['error' => 'Invalid channel name']);
        }

        $infoParam = isset($query['info']) ? (string) $query['info'] : '';
        $info = $infoParam !== '' ? explode(',', $infoParam) : [];

        if (in_array('user_count', $info, true) && Protocol::channelType($channel) !== 'presence') {
            return $this->json(400, ['error' => 'user_count can only be requested for presence channels']);
        }

        $data = $this->channels->channelInfo($app->appId, $channel, $info);
        if ($data === null) {
            return $this->json(200, ['occupied' => false]);
        }

        return $this->json(200, $data);
    }

    private function getChannelUsers(App $app, string $channel): array
    {
        if (!Protocol::validChannel($channel)) {
            return $this->json(400, ['error' => 'Invalid channel name']);
        }

        if (Protocol::channelType($channel) !== 'presence') {
            return $this->json(400, ['error' => 'Users query is only supported on presence channels']);
        }

        $users = $this->channels->users($app->appId, $channel);
        if ($users === null) {
            return $this->json(200, ['users' => []]);
        }

        return $this->json(200, $users);
    }

    /**
     * @param int $status
     * @param mixed $data
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function json(int $status, mixed $data): array
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return [
            $status,
            [
                'Content-Type' => 'application/json',
                'Content-Length' => (string) strlen($body),
            ],
            $body,
        ];
    }
}
