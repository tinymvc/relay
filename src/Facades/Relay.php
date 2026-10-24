<?php

namespace Spark\Relay\Facades;

use Spark\Facades\Facade;
use Spark\Relay\Relay as BaseRelay;

/**
 * Facade Relay
 *
 * @method static \Pusher\Pusher client()
 * @method static mixed trigger(array|string $channels, string $event, array|string $data, ?string $socketId = null)
 * @method static string authorize(string $channel, string $socketId, ?array $presence = null)
 * @method static object stats()
 *
 * @package Spark\Relay\Facades
 */
class Relay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseRelay::class;
    }
}
