<?php

$loader = require __DIR__ . '/../../vendor/autoload.php';
if ($source = getenv('RELAY_PUSHER_SOURCE')) {
    $loader->setPsr4('Pusher\\', $source . '/src');
}

use Pusher\Pusher;

$clientConfig = [
    'host' => '127.0.0.1',
    'port' => (int) (getenv('RELAY_TEST_PORT') ?: 8088),
    'scheme' => 'http',
    'useTLS' => false,
    'timeout' => 5,
];
$pusher = new Pusher('e2e-key', 'e2e-secret', '10', $clientConfig);

$action = $argv[1] ?? '';

switch ($action) {
    case 'restart':
    case 'get_stats':
        $application = new \Spark\Foundation\Application(dirname(__DIR__, 2));
        $application->setConfig('app.storage_dir', sys_get_temp_dir());
        $application->setConfig('relay.server.restart_file', getenv('RELAY_TEST_RESTART_FILE'));
        $application->setConfig('relay.apps', [['app_id' => '10', 'key' => 'e2e-key', 'secret' => 'e2e-secret']]);
        $application->setConfig('relay.client', $clientConfig);
        $commands = new \Spark\Console\Commands();
        $application->instance(\Spark\Console\Commands::class, $commands);
        $application->alias('commands', \Spark\Console\Commands::class);
        $provider = new \Spark\Relay\RelayServiceProvider();
        $provider->register();
        $provider->boot();

        if ($action === 'restart') {
            exit($application->call($commands->getCommand('relay:restart')['callback'], ['args' => []]));
        }
        echo json_encode(\Spark\Relay\Facades\Relay::stats());
        break;

    case 'trigger':
        $channel = $argv[2];
        $event = $argv[3];
        $data = json_decode($argv[4], true) ?? $argv[4];
        $socketId = $argv[5] ?? null;
        $params = $socketId ? ['socket_id' => $socketId] : [];
        $resp = $pusher->trigger($channel, $event, $data, $params);
        echo json_encode(['success' => true, 'response' => $resp]);
        break;

    case 'batch':
        $batch = json_decode($argv[2], true);
        $resp = $pusher->triggerBatch($batch);
        echo json_encode(['success' => true, 'response' => $resp]);
        break;

    case 'get_channels':
        $params = [];
        if (!empty($argv[2])) {
            $params['filter_by_prefix'] = $argv[2];
        }
        if (!empty($argv[3])) {
            $params['info'] = $argv[3];
        }
        $resp = $pusher->getChannels($params);
        echo json_encode($resp);
        break;

    case 'get_channel':
        $channel = $argv[2];
        $params = [];
        if (!empty($argv[3])) {
            $params['info'] = $argv[3];
        }
        $resp = $pusher->getChannelInfo($channel, $params);
        echo json_encode($resp);
        break;

    case 'get_users':
        $channel = $argv[2];
        $resp = $pusher->getPresenceUsers($channel);
        echo json_encode($resp);
        break;

    case 'auth':
        $channel = $argv[2];
        $socketId = $argv[3];
        $presence = !empty($argv[4]) ? $argv[4] : null;
        $resp = $pusher->authorizeChannel($channel, $socketId, $presence);
        echo $resp;
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
