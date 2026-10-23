<?php

namespace Tests\Unit;

use Spark\Relay\Protocol;
use Spark\Testing\TestCase;

class ProtocolTest extends TestCase
{
    public function testFrameNeverDoubleEncodesStringData(): void
    {
        $rawJson = '{"message":"hello world","nested":{"key":123}}';
        $frame = Protocol::frame('test-event', $rawJson, 'test-channel');
        $decoded = json_decode($frame, true);

        $this->assertEquals('test-event', $decoded['event']);
        $this->assertEquals('test-channel', $decoded['channel']);
        // Crucial: data inside the frame is the exact raw JSON string
        $this->assertEquals($rawJson, $decoded['data']);
    }

    public function testFrameEncodesArrayData(): void
    {
        $arrayData = ['count' => 5, 'active' => true];
        $frame = Protocol::frame('my-event', $arrayData);
        $decoded = json_decode($frame, true);

        $this->assertEquals('my-event', $decoded['event']);
        $this->assertArrayNotHasKey('channel', $decoded);
        $this->assertEquals(json_encode($arrayData), $decoded['data']);
    }

    public function testConnectionEstablishedFrame(): void
    {
        $frame = Protocol::connectionEstablished('123.456', 30);
        $decoded = json_decode($frame, true);

        $this->assertEquals('pusher:connection_established', $decoded['event']);
        $data = json_decode($decoded['data'], true);
        $this->assertEquals('123.456', $data['socket_id']);
        $this->assertEquals(30, $data['activity_timeout']);
    }

    public function testErrorFrame(): void
    {
        $frame = Protocol::error(4001, 'Application not found');
        $decoded = json_decode($frame, true);

        $this->assertEquals('pusher:error', $decoded['event']);
        $data = json_decode($decoded['data'], true);
        $this->assertEquals(4001, $data['code']);
        $this->assertEquals('Application not found', $data['message']);
    }

    public function testSubscriptionSucceededPublic(): void
    {
        $frame = Protocol::subscriptionSucceeded('my-channel');
        $decoded = json_decode($frame, true);

        $this->assertEquals('pusher_internal:subscription_succeeded', $decoded['event']);
        $this->assertEquals('my-channel', $decoded['channel']);
        $this->assertEquals('{}', $decoded['data']);
    }

    public function testSubscriptionSucceededPresence(): void
    {
        $frame = Protocol::subscriptionSucceeded('presence-chat', [
            'count' => 1,
            'ids' => ['user-1'],
            'hash' => ['user-1' => ['name' => 'Alice']],
        ]);
        $decoded = json_decode($frame, true);

        $this->assertEquals('pusher_internal:subscription_succeeded', $decoded['event']);
        $this->assertEquals('presence-chat', $decoded['channel']);
        $data = json_decode($decoded['data'], true);
        $this->assertEquals(1, $data['presence']['count']);
        $this->assertEquals(['user-1'], $data['presence']['ids']);
        $this->assertEquals(['name' => 'Alice'], $data['presence']['hash']['user-1']);
    }

    public function testEmptyPresenceHashEncodesAsObject(): void
    {
        $frame = Protocol::subscriptionSucceeded('presence-chat', [
            'count' => 0,
            'ids' => [],
            'hash' => [],
        ]);
        $decoded = json_decode($frame, true);
        // Ensure data string contains "hash":{} and not "hash":[]
        $this->assertStringContainsString('"hash":{}', $decoded['data']);
    }

    public function testMemberAddedAndRemovedFrames(): void
    {
        $added = Protocol::memberAdded('presence-room', 'u123', ['name' => 'Bob']);
        $decodedAdd = json_decode($added, true);
        $this->assertEquals('pusher_internal:member_added', $decodedAdd['event']);
        $dataAdd = json_decode($decodedAdd['data'], true);
        $this->assertEquals('u123', $dataAdd['user_id']);
        $this->assertEquals(['name' => 'Bob'], $dataAdd['user_info']);

        $removed = Protocol::memberRemoved('presence-room', 'u123');
        $decodedRem = json_decode($removed, true);
        $this->assertEquals('pusher_internal:member_removed', $decodedRem['event']);
        $dataRem = json_decode($decodedRem['data'], true);
        $this->assertEquals('u123', $dataRem['user_id']);
    }

    public function testChannelValidationAndTypes(): void
    {
        $this->assertTrue(Protocol::validChannel('public-news'));
        $this->assertTrue(Protocol::validChannel('private-chat.123'));
        $this->assertTrue(Protocol::validChannel('presence-room_abc-1'));
        $this->assertTrue(Protocol::validChannel('channel-=@,.;'));
        $this->assertFalse(Protocol::validChannel(''));
        $this->assertFalse(Protocol::validChannel('invalid channel with space'));
        $this->assertFalse(Protocol::validChannel('channel#hash'));
        $this->assertFalse(Protocol::validChannel(str_repeat('a', 165)));

        $this->assertEquals('public', Protocol::channelType('public-news'));
        $this->assertEquals('private', Protocol::channelType('private-chat'));
        $this->assertEquals('presence', Protocol::channelType('presence-room'));
    }

    public function testSocketIdValidationAndGeneration(): void
    {
        $this->assertTrue(Protocol::validSocketId('1234.5678'));
        $this->assertFalse(Protocol::validSocketId('1234'));
        $this->assertFalse(Protocol::validSocketId('abc.123'));
        $this->assertFalse(Protocol::validSocketId('123.'));

        $newId = Protocol::newSocketId();
        $this->assertTrue(Protocol::validSocketId($newId));
    }
}
