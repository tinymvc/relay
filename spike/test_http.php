<?php

require __DIR__ . '/../vendor/autoload.php';

use Pusher\Pusher;

echo "1. Testing real Pusher SDK trigger()..." . PHP_EOL;

$pusher = new Pusher('test-key', 'test-secret', '1', [
    'host' => '127.0.0.1',
    'port' => 8085,
    'scheme' => 'http',
    'useTLS' => false,
    'timeout' => 5,
]);

try {
    $response = $pusher->trigger('test-channel', 'test-event', ['message' => 'hello from SDK']);
    echo "SDK trigger success! Response: " . json_encode($response) . PHP_EOL;
} catch (\Throwable $e) {
    echo "SDK trigger error: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "2. Testing curl POST larger than 2 KB with Expect: 100-continue..." . PHP_EOL;

$largeData = json_encode([
    'name' => 'large-event',
    'channels' => ['test-channel'],
    'data' => str_repeat('X', 3000), // > 2KB
]);

$ch = curl_init('http://127.0.0.1:8085/apps/1/events');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $largeData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Expect: 100-continue',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Curl POST completed with HTTP $httpCode. Response: $result" . PHP_EOL;
