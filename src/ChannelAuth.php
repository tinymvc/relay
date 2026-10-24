<?php

namespace Spark\Relay;

use function array_slice;
use function is_array;
use function preg_match;
use function preg_replace;

class ChannelAuth
{
    /** @var array<string, callable> */
    private static array $channels = [];

    /**
     * Define an authorization callback for a channel pattern.
     *
     * Example:
     *   ChannelAuth::define('private-chat.{id}', fn ($user, $id) => $user->canJoinChat($id));
     *   ChannelAuth::define('presence-room.{id}', fn ($user, $id) => ['id' => $user->id, 'info' => ['name' => $user->name]]);
     */
    public static function define(string $pattern, callable $callback): void
    {
        self::$channels[$pattern] = $callback;
    }

    /**
     * Authorize a channel name for a user.
     *
     * @param mixed $user The authenticated user
     * @param string $channel The channel name requested by client
     * @return array|bool Returns false if unauthorized; true or presence data array if authorized.
     */
    public static function authorize(mixed $user, string $channel): array|bool
    {
        if (!Protocol::validChannel($channel)) {
            return false;
        }

        foreach (self::$channels as $pattern => $callback) {
            $regex = self::patternToRegex($pattern);

            if (preg_match($regex, $channel, $matches)) {
                $params = array_slice($matches, 1);
                $result = $callback($user, ...$params);

                return $result === true || is_array($result) ? $result : false;
            }
        }

        return false;
    }

    public static function clear(): void
    {
        self::$channels = [];
    }

    private static function patternToRegex(string $pattern): string
    {
        $quoted = preg_quote($pattern, '#');
        $regex = preg_replace('/\\\\\{[a-zA-Z_][a-zA-Z0-9_]*\\\\\}/', '([^.]+)', $quoted);

        return "#\\A{$regex}\\z#";
    }
}
