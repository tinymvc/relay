<?php

require __DIR__ . '/../../vendor/autoload.php';

use Pusher\Pusher;

$pusher = new Pusher('e2e-key', 'e2e-secret', '10', [
    'host' => '127.0.0.1',
    'port' => 8088,
    'scheme' => 'http',
    'useTLS' => false,
    'timeout' => 5,
]);

$action = $argv[1] ?? '';

switch ($action) {
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
