<?php

namespace Spark\Relay;

use function array_filter;
use function is_string;
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
        foreach (self::$channels as $pattern => $callback) {
            $regex = self::patternToRegex($pattern);

            if (preg_match($regex, $channel, $matches)) {
                $params = array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
                $result = $callback($user, ...$params);

                if ($result === false || $result === null) {
                    return false;
                }

                return $result;
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
        // Quote special characters except for {param}
        $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^.]+)', $pattern);
        $regex = str_replace(['.', '-'], ['\.', '\-'], $regex);

        // Fix escaped brackets in named capture group
        $regex = preg_replace('/\(\?P<([a-zA-Z0-9_]+)>\[\^\\\.\]\+\)/', '(?P<$1>[^.]+)', $regex);

        return "#^{$regex}$#";
    }
}
