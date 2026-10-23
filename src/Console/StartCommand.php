<?php

namespace Spark\Relay\Console;

use React\EventLoop\Loop;
use Spark\Console\Prompt;
use Spark\Relay\Api;
use Spark\Relay\App;
use Spark\Relay\Channels;
use Spark\Relay\Server;
use function array_keys;
use function config;
use function ctype_digit;
use function get_class;
use function implode;
use function is_array;
use function preg_match;
use function trim;

class StartCommand
{
    /**
     * Handle the `relay:start` command.
     *
     * @param array $args
     * @return int
     */
    public function handle(array $args): int
    {
        $rawApps = config('relay.apps', []);
        if (!is_array($rawApps) || empty($rawApps)) {
            Prompt::error('No Relay apps configured. Check config/relay.php or RELAY_APP_* environment variables.');
            return 1;
        }

        try {
            $apps = App::loadAll($rawApps);
        } catch (\Throwable $e) {
            Prompt::error('Invalid Relay app configuration: ' . $e->getMessage());
            return 1;
        }

        $serverConfig = config('relay.server', []);
        if (!is_array($serverConfig)) {
            $serverConfig = [];
        }

        [$host, $port] = $this->resolveAddress($args, $serverConfig);
        $debug = isset($args['debug']) && (bool) $args['debug'];

        $serverConfig['host'] = $host;
        $serverConfig['port'] = $port;

        $channels = new Channels();
        $api = new Api($channels, $apps);
        $loop = Loop::get();

        $server = new Server(
            channels: $channels,
            apps: $apps,
            api: $api,
            config: $serverConfig,
            loop: $loop,
            debug: $debug
        );

        Prompt::info("Relay WebSocket & HTTP API Server starting...");
        Prompt::comment("  Address:    [http://{$host}:{$port}]");
        Prompt::comment("  Apps:       [" . implode(', ', array_keys($apps)) . "]");
        Prompt::comment("  Event Loop: [" . get_class($loop) . "]");
        if ($debug) {
            Prompt::comment("  Debug Mode: [ENABLED]");
        }
        Prompt::comment("  Press Ctrl+C to stop the server.");
        Prompt::newline();

        $server->start();
        $loop->run();

        Prompt::info('Relay server stopped.');
        return 0;
    }

    /**
     * Resolve host and port from CLI arguments or default config.
     *
     * @param array $args
     * @param array $config
     * @return array{0: string, 1: int}
     */
    private function resolveAddress(array $args, array $config): array
    {
        $host = (string) ($args['host'] ?? $config['host'] ?? '0.0.0.0');
        $port = (int) ($args['port'] ?? $config['port'] ?? 8080);

        $pos = trim((string) ($args['_args'][0] ?? ''));
        if ($pos !== '') {
            if (ctype_digit($pos)) {
                $port = (int) $pos;
            } elseif (preg_match('/^(\[[0-9a-f:.]+\]|[^:\s]+):(\d+)$/i', $pos, $m)) {
                $host = $m[1];
                $port = (int) $m[2];
            } else {
                $host = $pos;
            }
        }

        return [$host, $port];
    }
}
