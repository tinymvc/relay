<?php

namespace Tests\Unit;

use Spark\Relay\App;
use Spark\Relay\Channels;
use Spark\Testing\TestCase;

class ChannelsTest extends TestCase
{
    private App $app;
    private Channels $channels;

    protected function setUp(): void
    {
        $this->app = new App(
            appId: '1',
            key: 'testkey',
            secret: 'testsecret',
            clientEvents: true
        );
        $this->channels = new Channels();
    }

    public function testPublicChannelSubscribeAndUnsubscribe(): void
    {
        $conn = new FakeConnection('1.1', $this->app);

        $this->assertTrue($this->channels->subscribe($conn, 'news'));
        $this->assertTrue($this->channels->occupied('1', 'news'));

        // Sent subscription_succeeded
        $last = $conn->lastSentDecoded();
        $this->assertEquals('pusher_internal:subscription_succeeded', $last['event']);
        $this->assertEquals('news', $last['channel']);
        $this->assertEquals('{}', $last['data']);

        // Unsubscribe
        $this->assertTrue($this->channels->unsubscribe($conn, 'news'));
        // Channel is now empty and deleted
        $this->assertFalse($this->channels->occupied('1', 'news'));
    }

    public function testPrivateChannelSubscribe(): void
    {
        $conn = new FakeConnection('2.2', $this->app);

        $this->assertTrue($this->channels->subscribe($conn, 'private-chat.1'));
        $this->assertTrue($this->channels->occupied('1', 'private-chat.1'));

        $last = $conn->lastSentDecoded();
        $this->assertEquals('pusher_internal:subscription_succeeded', $last['event']);
        $this->assertEquals('private-chat.1', $last['channel']);
    }

    public function testPresenceSubscriptionAndMultipleSocketsOfOneUser(): void
    {
        $conn1 = new FakeConnection('10.1', $this->app);
        $conn2 = new FakeConnection('10.2', $this->app); // same user u1, 2nd tab
        $conn3 = new FakeConnection('20.1', $this->app); // different user u2

        $user1Data = json_encode(['user_id' => 'u1', 'user_info' => ['name' => 'Alice']]);
        $user2Data = json_encode(['user_id' => 'u2', 'user_info' => ['name' => 'Bob']]);

        // Alice connects first socket
        $this->channels->subscribe($conn1, 'presence-room', $user1Data);
        $alice1Reply = $conn1->lastSentDecoded();
        $this->assertEquals('pusher_internal:subscription_succeeded', $alice1Reply['event']);
        $roster1 = json_decode($alice1Reply['data'], true);
        $this->assertEquals(1, $roster1['presence']['count']);
        $this->assertEquals(['u1'], $roster1['presence']['ids']);

        // Bob connects
        $this->channels->subscribe($conn3, 'presence-room', $user2Data);

        // Alice should have received member_added for Bob
        $aliceReceived = $conn1->lastSentDecoded();
        $this->assertEquals('pusher_internal:member_added', $aliceReceived['event']);
        $bobAdded = json_decode($aliceReceived['data'], true);
        $this->assertEquals('u2', $bobAdded['user_id']);
        $this->assertEquals(['name' => 'Bob'], $bobAdded['user_info']);

        // Alice connects second socket (second tab)
        $conn1SentBefore = count($conn1->sent);
        $conn3SentBefore = count($conn3->sent);

        $this->channels->subscribe($conn2, 'presence-room', $user1Data);

        // Neither Bob nor Alice tab 1 should receive member_added (Alice was already in the room)
        $this->assertCount($conn1SentBefore, $conn1->sent);
        $this->assertCount($conn3SentBefore, $conn3->sent);

        // Alice tab 2 received subscription_succeeded
        $alice2Reply = $conn2->lastSentDecoded();
        $this->assertEquals('pusher_internal:subscription_succeeded', $alice2Reply['event']);
        $roster2 = json_decode($alice2Reply['data'], true);
        $this->assertEquals(2, $roster2['presence']['count']);

        // Alice closes tab 1
        $this->channels->unsubscribe($conn1, 'presence-room');

        // Bob should NOT receive member_removed because Alice still has tab 2 connected!
        $this->assertCount($conn3SentBefore, $conn3->sent);

        // Alice closes tab 2 (last socket)
        $this->channels->unsubscribe($conn2, 'presence-room');

        // Now Bob MUST receive member_removed for Alice!
        $bobReceived = $conn3->lastSentDecoded();
        $this->assertEquals('pusher_internal:member_removed', $bobReceived['event']);
        $aliceRemoved = json_decode($bobReceived['data'], true);
        $this->assertEquals('u1', $aliceRemoved['user_id']);
    }

    public function testIdempotentResubscribe(): void
    {
        $conn = new FakeConnection('5.5', $this->app);
        $this->channels->subscribe($conn, 'chat');
        $count1 = count($conn->sent);

        // Subscribe again
        $this->channels->subscribe($conn, 'chat');
        $this->assertCount($count1 + 1, $conn->sent);

        $last = $conn->lastSentDecoded();
        $this->assertEquals('pusher_internal:subscription_succeeded', $last['event']);

        $info = $this->channels->channelInfo('1', 'chat', ['subscription_count']);
        $this->assertEquals(1, $info['subscription_count']);
    }

    public function testDisconnectCleanupCleansAllChannels(): void
    {
        $conn = new FakeConnection('7.7', $this->app);
        $this->channels->subscribe($conn, 'ch1');
        $this->channels->subscribe($conn, 'ch2');
        $this->channels->subscribe($conn, 'ch3');

        $this->assertEquals(1, $this->channels->connectionCount('1'));
        $this->assertTrue($this->channels->occupied('1', 'ch1'));
        $this->assertTrue($this->channels->occupied('1', 'ch2'));
        $this->assertTrue($this->channels->occupied('1', 'ch3'));

        $this->channels->disconnect($conn);

        $this->assertEquals(0, $this->channels->connectionCount('1'));
        $this->assertFalse($this->channels->occupied('1', 'ch1'));
        $this->assertFalse($this->channels->occupied('1', 'ch2'));
        $this->assertFalse($this->channels->occupied('1', 'ch3'));
    }

    public function testPublishFanOutAndSocketExclusion(): void
    {
        $sender = new FakeConnection('1.1', $this->app);
        $receiver1 = new FakeConnection('2.2', $this->app);
        $receiver2 = new FakeConnection('3.3', $this->app);

        $this->channels->subscribe($sender, 'updates');
        $this->channels->subscribe($receiver1, 'updates');
        $this->channels->subscribe($receiver2, 'updates');

        $data = '{"message":"hello"}';
        $this->channels->publish($this->app, 'updates', 'new-post', $data, exceptSocketId: '1.1');

        // Sender did NOT receive it (only has the 1 subscription_succeeded frame)
        $this->assertCount(1, $sender->sent);
        $this->assertEquals('pusher_internal:subscription_succeeded', $sender->lastSentDecoded()['event']);

        // Receivers received it
        $this->assertCount(2, $receiver1->sent);
        $r1 = $receiver1->lastSentDecoded();
        $this->assertEquals('new-post', $r1['event']);
        $this->assertEquals('updates', $r1['channel']);
        $this->assertEquals($data, $r1['data']);

        $this->assertCount(2, $receiver2->sent);
        $r2 = $receiver2->lastSentDecoded();
        $this->assertEquals('new-post', $r2['event']);
    }

    public function testFanOutSurvivesFailingConnection(): void
    {
        $badConn = new FakeConnection('bad.1', $this->app);
        $goodConn = new FakeConnection('good.1', $this->app);

        $this->channels->subscribe($badConn, 'topic');
        $this->channels->subscribe($goodConn, 'topic');

        // Now make badConn throw on next send
        $badConn->shouldThrowOnSend = true;

        // Publish to topic - badConn throws, but goodConn MUST receive the frame
        $this->channels->publish($this->app, 'topic', 'alert', '{"text":"test"}');

        $goodLast = $goodConn->lastSentDecoded();
        $this->assertEquals('alert', $goodLast['event']);
    }

    public function testWhisperClientEvents(): void
    {
        $conn1 = new FakeConnection('1.1', $this->app);
        $conn2 = new FakeConnection('2.2', $this->app);

        $this->channels->subscribe($conn1, 'private-chat.1');
        $this->channels->subscribe($conn2, 'private-chat.1');

        // Valid whisper
        $whisperData = '{"typing":true}';
        $success = $this->channels->whisper($conn1, 'private-chat.1', 'client-typing', $whisperData);
        $this->assertTrue($success);

        // Receiver2 got the whisper
        $last2 = $conn2->lastSentDecoded();
        $this->assertEquals('client-typing', $last2['event']);
        $this->assertEquals('private-chat.1', $last2['channel']);
        $this->assertEquals($whisperData, $last2['data']);

        // Sender did not get own whisper
        $senderLast = $conn1->lastSentDecoded();
        $this->assertEquals('pusher_internal:subscription_succeeded', $senderLast['event']);

        // Reject whisper if not starting with client-
        $this->assertFalse($this->channels->whisper($conn1, 'private-chat.1', 'fake-event', '{}'));

        // Reject whisper on public channel
        $this->channels->subscribe($conn1, 'public-feed');
        $this->assertFalse($this->channels->whisper($conn1, 'public-feed', 'client-typing', '{}'));

        // Reject whisper if app has clientEvents disabled
        $appNoClient = new App('2', 'k2', 's2', clientEvents: false);
        $connNoClient = new FakeConnection('9.9', $appNoClient);
        $this->channels->subscribe($connNoClient, 'private-room');
        $this->assertFalse($this->channels->whisper($connNoClient, 'private-room', 'client-test', '{}'));
    }

    public function testChannelInfoAndUsersQueries(): void
    {
        $conn1 = new FakeConnection('1.1', $this->app);
        $conn2 = new FakeConnection('2.2', $this->app);

        $this->channels->subscribe($conn1, 'presence-team', json_encode(['user_id' => '100', 'user_info' => []]));
        $this->channels->subscribe($conn2, 'presence-team', json_encode(['user_id' => '200', 'user_info' => []]));

        $info = $this->channels->channelInfo('1', 'presence-team', ['user_count', 'subscription_count']);
        $this->assertTrue($info['occupied']);
        $this->assertEquals(2, $info['user_count']);
        $this->assertEquals(2, $info['subscription_count']);

        $users = $this->channels->users('1', 'presence-team');
        $this->assertCount(2, $users['users']);
        $this->assertEquals('100', $users['users'][0]['id']);
        $this->assertEquals('200', $users['users'][1]['id']);

        $list = $this->channels->list('1', 'presence-', ['user_count']);
        $this->assertTrue(isset($list['channels']->{'presence-team'}));
        $this->assertEquals(2, $list['channels']->{'presence-team'}->user_count);
    }
}
