<?php

require __DIR__ . '/../vendor/autoload.php';

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use GuzzleHttp\Psr7\Message;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;

$port = 8085;
$negotiator = new ServerNegotiator(new RequestVerifier());
$socket = new SocketServer("127.0.0.1:{$port}");

echo "Spike server (SocketServer) listening on http://127.0.0.1:{$port}" . PHP_EOL;

$socket->on('connection', function (ConnectionInterface $conn) use ($negotiator) {
    $buffer = '';
    $isWebSocket = false;
    $messageBuffer = null;
    $expectedBodyLength = 0;
    $bodyBuffer = '';
    $parsedRequest = null;

    $conn->on('data', function ($chunk) use (
        $conn,
        $negotiator,
        &$buffer,
        &$isWebSocket,
        &$messageBuffer,
        &$expectedBodyLength,
        &$bodyBuffer,
        &$parsedRequest
    ) {
        if ($isWebSocket) {
            $messageBuffer->onData($chunk);
            return;
        }

        $buffer .= $chunk;

        if ($parsedRequest === null) {
            $headerEnd = strpos($buffer, "\r\n\r\n");
            if ($headerEnd === false) {
                return; // Wait for full headers
            }

            $rawHeaders = substr($buffer, 0, $headerEnd);
            $leftover = substr($buffer, $headerEnd + 4);

            try {
                $parsedRequest = Message::parseRequest($rawHeaders . "\r\n\r\n");
            } catch (\Throwable $e) {
                $conn->write("HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
                $conn->end();
                return;
            }

            $path = $parsedRequest->getUri()->getPath();
            $upgrade = strtolower($parsedRequest->getHeaderLine('Upgrade'));

            // WebSocket Upgrade Branch
            if ($upgrade === 'websocket') {
                $response = $negotiator->handshake($parsedRequest);
                if ($response->getStatusCode() !== 101) {
                    $conn->write(Message::toString($response));
                    $conn->end();
                    return;
                }

                $conn->write(Message::toString($response));
                $isWebSocket = true;

                $sendFrame = function (string $data, int $opcode = Frame::OP_TEXT) use ($conn) {
                    $frame = new Frame($data, true, $opcode);
                    $conn->write($frame->getContents());
                };

                $messageBuffer = new MessageBuffer(
                    new CloseFrameChecker(),
                    function ($message) use ($sendFrame, $conn) {
                        $payload = $message->getPayload();
                        echo "[WS Inbound] " . $payload . PHP_EOL;
                        $data = json_decode($payload, true);
                        $event = $data['event'] ?? '';

                        if ($event === 'pusher:ping') {
                            $pong = json_encode([
                                'event' => 'pusher:pong',
                                'data' => '{}',
                            ]);
                            $sendFrame($pong);
                            echo "[WS Outbound] Sent pusher:pong" . PHP_EOL;
                        } elseif ($event === 'test:close_4200') {
                            echo "[WS] Sending server close 4200" . PHP_EOL;
                            $closePayload = pack('n', 4200) . 'Server restart';
                            $frame = new Frame($closePayload, true, Frame::OP_CLOSE);
                            $conn->write($frame->getContents());
                            $conn->end();
                        }
                    },
                    function (Frame $frame) use ($sendFrame, $conn) {
                        $opcode = $frame->getOpcode();
                        if ($opcode === Frame::OP_PING) {
                            $sendFrame($frame->getPayload(), Frame::OP_PONG);
                        } elseif ($opcode === Frame::OP_CLOSE) {
                            $sendFrame($frame->getPayload(), Frame::OP_CLOSE);
                            $conn->end();
                        }
                    },
                    true
                );

                // Send pusher:connection_established
                $established = json_encode([
                    'event' => 'pusher:connection_established',
                    'data' => json_encode([
                        'socket_id' => '12345.67890',
                        'activity_timeout' => 3,
                    ]),
                ]);
                $sendFrame($established);
                echo "[WS] Sent pusher:connection_established" . PHP_EOL;

                if (strlen($leftover) > 0) {
                    $messageBuffer->onData($leftover);
                }

                return;
            }

            // HTTP API Branch
            if (strtolower($parsedRequest->getHeaderLine('Expect')) === '100-continue') {
                $conn->write("HTTP/1.1 100 Continue\r\n\r\n");
            }

            $contentLengthHeader = $parsedRequest->getHeaderLine('Content-Length');
            $expectedBodyLength = $contentLengthHeader !== '' ? (int) $contentLengthHeader : 0;
            $bodyBuffer = $leftover;
        } else {
            $bodyBuffer .= $chunk;
        }

        if (strlen($bodyBuffer) >= $expectedBodyLength) {
            $rawBody = substr($bodyBuffer, 0, $expectedBodyLength);
            $path = $parsedRequest->getUri()->getPath();

            if (preg_match('#^/apps/([^/]+)/events$#', $path) && $parsedRequest->getMethod() === 'POST') {
                echo "[HTTP API] Received POST /events with body size: " . strlen($rawBody) . " bytes" . PHP_EOL;
                $respBody = '{}';
                $resp = "HTTP/1.1 200 OK\r\n" .
                    "Content-Type: application/json\r\n" .
                    "Content-Length: " . strlen($respBody) . "\r\n" .
                    "Connection: close\r\n\r\n" .
                    $respBody;
                $conn->write($resp);
                $conn->end();
            } else {
                $respBody = "Not Found\n";
                $resp = "HTTP/1.1 404 Not Found\r\n" .
                    "Content-Type: text/plain\r\n" .
                    "Content-Length: " . strlen($respBody) . "\r\n" .
                    "Connection: close\r\n\r\n" .
                    $respBody;
                $conn->write($resp);
                $conn->end();
            }
        }
    });

    $conn->on('close', function () {
        echo "[Connection] Closed" . PHP_EOL;
    });
});
