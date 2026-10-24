<?php

namespace Spark\Relay;

use function abs;
use function hash_equals;
use function hash_hmac;
use function implode;
use function is_int;
use function is_string;
use function ksort;
use function md5;
use function strtolower;
use function strtoupper;
use function time;

class Signature
{
    /**
     * Decode query values exactly once and reject ambiguous or structured parameters.
     * The same normalized parameters are used for signing and endpoint behavior.
     */
    public static function parseQuery(array|string $query): ?array
    {
        $pairs = [];
        if (is_string($query)) {
            foreach (explode('&', $query) as $pair) {
                if ($pair === '') {
                    continue;
                }
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $pairs[] = [urldecode($key), urldecode($value)];
            }
        } else {
            foreach ($query as $key => $value) {
                $pairs[] = [$key, $value];
            }
        }

        $params = [];
        foreach ($pairs as [$key, $value]) {
            $key = strtolower((string) $key);
            if (
                !preg_match('/\A[a-z0-9_]+\z/', $key)
                || (!is_string($value) && !is_int($value)) || isset($params[$key])
            ) {
                return null;
            }
            $params[$key] = (string) $value;
        }

        return $params;
    }

    /**
     * Compute channel auth string: "key:hmac".
     */
    public static function channelAuth(App $app, string $socketId, string $channel, ?string $channelData = null): string
    {
        $stringToSign = $channelData !== null && $channelData !== ''
            ? "$socketId:$channel:$channelData"
            : "$socketId:$channel";

        $hmac = hash_hmac('sha256', $stringToSign, $app->secret);

        return "{$app->key}:$hmac";
    }

    /**
     * Verify channel auth string.
     */
    public static function verifyChannelAuth(App $app, string $auth, string $socketId, string $channel, ?string $channelData = null): bool
    {
        $expected = self::channelAuth($app, $socketId, $channel, $channelData);

        return hash_equals($expected, $auth);
    }

    /**
     * Verify an incoming HTTP API request against Pusher signature specifications.
     *
     * @param App $app
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path URL path as received (e.g. /apps/1/events)
     * @param array|string $query Query parameters array or raw query string
     * @param string $rawBody Raw request payload
     * @param int $now Current timestamp (0 to use time())
     * @return string|null Null on success, or an error reason string on failure.
     */
    public static function verifyApiRequest(
        App $app,
        string $method,
        string $path,
        array|string $query,
        string $rawBody = '',
        int $now = 0
    ): ?string {
        $params = self::parseQuery($query);
        if ($params === null) {
            return 'Invalid query parameters';
        }

        // 1. Verify auth_key
        $authKey = $params['auth_key'] ?? null;
        if (!is_string($authKey) || !hash_equals($app->key, $authKey)) {
            return 'Invalid auth_key';
        }

        // 2. Verify auth_timestamp
        $authTimestamp = $params['auth_timestamp'] ?? null;
        if (!is_string($authTimestamp) || !preg_match('/\A[0-9]{1,12}\z/', $authTimestamp)) {
            return 'Missing or invalid auth_timestamp';
        }

        $now = $now > 0 ? $now : time();
        if (abs($now - (int) $authTimestamp) > Protocol::MAX_TIMESTAMP_SKEW) {
            return 'Timestamp expired or skewed';
        }

        if (($params['auth_version'] ?? null) !== '1.0') {
            return 'Unsupported auth_version';
        }

        // 3. For POST, verify body_md5
        $method = strtoupper($method);
        if ($method === 'POST' || $rawBody !== '') {
            $bodyMd5 = $params['body_md5'] ?? null;
            if (!is_string($bodyMd5) || !hash_equals(md5($rawBody), $bodyMd5)) {
                return 'Invalid body_md5';
            }
        }

        // 4. Verify auth_signature
        $authSignature = $params['auth_signature'] ?? null;
        if (!is_string($authSignature) || $authSignature === '') {
            return 'Missing auth_signature';
        }

        unset($params['auth_signature']);
        ksort($params);

        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        $paramString = implode('&', $parts);

        $stringToSign = "{$method}\n{$path}\n{$paramString}";
        $expectedSignature = hash_hmac('sha256', $stringToSign, $app->secret);

        if (!hash_equals($expectedSignature, $authSignature)) {
            return 'Invalid auth_signature';
        }

        return null;
    }
}
