<?php

require __DIR__ . '/../vendor/autoload.php';

$classes = [
    'React\Http\HttpServer',
    'React\Http\Middleware\StreamingRequestMiddleware',
    'React\Stream\ThroughStream',
    'Ratchet\RFC6455\Handshake\ServerNegotiator',
    'Ratchet\RFC6455\Handshake\RequestVerifier',
    'Ratchet\RFC6455\Messaging\MessageBuffer',
    'Ratchet\RFC6455\Messaging\Frame',
    'Ratchet\RFC6455\Messaging\CloseFrameChecker',
];

foreach ($classes as $c) {
    echo $c . ': ' . (class_exists($c) ? 'OK' : 'MISSING') . PHP_EOL;
}
