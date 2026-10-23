<?php

namespace Spark\Relay;

use Spark\Console\Commands;
use Spark\Foundation\Providers\ServiceProvider;
use Spark\Relay\Console\StartCommand;

class RelayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Relay::class);
        $this->app->alias('relay', Relay::class);
    }

    public function boot(): void
    {
        if (is_cli() && $this->app->has('commands')) {
            /** @var Commands $commands */
            $commands = $this->app->get(Commands::class);
            $commands->addCommand('relay:start', [StartCommand::class, 'handle'])
                ->description('Start the Relay Pusher-compatible WebSocket and HTTP API server')
                ->help('Options: --host, --port, --debug');
        }
    }
}
