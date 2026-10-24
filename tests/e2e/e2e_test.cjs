const Pusher = require('pusher-js');
const { execFileSync } = require('child_process');

function sdk(action, ...args) {
    const output = execFileSync(process.env.PHP_BINARY || 'php', [__dirname + '/e2e_sdk_helper.php', action, ...args], { timeout: 10000 }).toString().trim();
    try {
        return JSON.parse(output);
    } catch (e) {
        return output;
    }
}

function createClient(options = {}) {
    return new Pusher('e2e-key', {
        wsHost: '127.0.0.1',
        wsPort: Number(process.env.RELAY_TEST_PORT || 8088),
        forceTLS: false,
        enabledTransports: ['ws'],
        disableStats: true,
        cluster: 'mt1',
        ...options
    });
}

async function runTests() {
    console.log('--- Starting Relay E2E Test Suite ---');

    // 1. Public Channel Delivery & Socket ID Exclusion
    console.log('\n[E2E 1] Testing Public Channel Delivery and Socket ID Exclusion...');
    const client1 = createClient();
    const client2 = createClient();

    await Promise.all([
        new Promise(res => client1.connection.bind('connected', res)),
        new Promise(res => client2.connection.bind('connected', res))
    ]);

    const channel1 = client1.subscribe('public-updates');
    const channel2 = client2.subscribe('public-updates');

    await new Promise(res => channel1.bind('pusher:subscription_succeeded', res));
    await new Promise(res => channel2.bind('pusher:subscription_succeeded', res));

    let client1GotBroadcast = false;
    let client2GotBroadcast = false;

    channel1.bind('new-item', (data) => {
        client1GotBroadcast = true;
    });
    channel2.bind('new-item', (data) => {
        client2GotBroadcast = true;
    });

    // Trigger without exclusion: both should get it
    sdk('trigger', 'public-updates', 'new-item', JSON.stringify({ msg: 'everyone' }));
    await new Promise(r => setTimeout(r, 300));
    if (!client1GotBroadcast || !client2GotBroadcast) {
        throw new Error('Public broadcast failed: both clients should receive the event');
    }
    console.log('✓ Public event delivered to all subscribers');

    // Trigger with socket_id exclusion (excluding client1)
    let client1Excluded = true;
    let client2GotExcludedEvent = false;

    channel1.bind('targeted', () => { client1Excluded = false; });
    channel2.bind('targeted', () => { client2GotExcludedEvent = true; });

    sdk('trigger', 'public-updates', 'targeted', JSON.stringify({ msg: 'except client1' }), client1.connection.socket_id);
    await new Promise(r => setTimeout(r, 300));
    if (!client1Excluded || !client2GotExcludedEvent) {
        throw new Error('Socket ID exclusion failed: client1 was not excluded');
    }
    console.log('✓ Socket ID exclusion successfully skipped excluded socket');

    // 2. Private Channel Auth Success & Failure
    console.log('\n[E2E 2] Testing Private Channel Auth Success & Failure...');
    
    // Client with valid authorizer
    const privClient = createClient({
        authorizer: (channel, options) => ({
            authorize: (socketId, callback) => {
                const authData = sdk('auth', channel.name, socketId);
                callback(null, authData);
            }
        })
    });
    await new Promise(res => privClient.connection.bind('connected', res));

    const privChannel = privClient.subscribe('private-orders.42');
    await new Promise(res => privChannel.bind('pusher:subscription_succeeded', res));
    console.log('✓ Private channel subscribed successfully with real SDK signature');

    let privEventReceived = false;
    privChannel.bind('order-shipped', (data) => {
        if (data.tracking === 'ABC123') privEventReceived = true;
    });
    sdk('trigger', 'private-orders.42', 'order-shipped', JSON.stringify({ tracking: 'ABC123' }));
    await new Promise(r => setTimeout(r, 300));
    if (!privEventReceived) {
        throw new Error('Private channel event was not delivered');
    }
    console.log('✓ Private channel event delivered');

    // Client with invalid authorizer (must receive 4009 error and keep connection open)
    const badAuthClient = createClient({
        authorizer: () => ({
            authorize: (socketId, callback) => {
                callback(null, { auth: 'e2e-key:badbadbadbadbadbadbadbadbadbadbad' });
            }
        })
    });
    await new Promise(res => badAuthClient.connection.bind('connected', res));

    let got4009Error = false;
    const errorHandler = (err) => {
        if (err && (err.code === 4009 || (err.data && err.data.code === 4009))) {
            got4009Error = true;
        }
    };
    badAuthClient.connection.bind('error', errorHandler);
    badAuthClient.bind('error', errorHandler);

    const badChannel = badAuthClient.subscribe('private-secret');
    badChannel.bind('pusher:subscription_error', () => {
        got4009Error = true;
    });

    await new Promise(r => setTimeout(r, 400));
    if (!got4009Error) {
        throw new Error('Expected 4009 error on invalid auth signature');
    }
    if (badAuthClient.connection.state !== 'connected') {
        throw new Error('Connection should stay open after 4009 channel auth error');
    }
    console.log('✓ Invalid channel auth returned 4009 error and connection remained open');

    // 3. Client Events (Whispers)
    console.log('\n[E2E 3] Testing Client Events (Whispers)...');
    const peerClient = createClient({
        authorizer: (channel) => ({
            authorize: (socketId, callback) => {
                callback(null, sdk('auth', channel.name, socketId));
            }
        })
    });
    await new Promise(res => peerClient.connection.bind('connected', res));
    const peerPrivChannel = peerClient.subscribe('private-orders.42');
    await new Promise(res => peerPrivChannel.bind('pusher:subscription_succeeded', res));

    let whisperReceived = false;
    peerPrivChannel.bind('client-typing', (data) => {
        if (data.user === 'privClient') whisperReceived = true;
    });

    privChannel.trigger('client-typing', { user: 'privClient' });
    await new Promise(r => setTimeout(r, 300));
    if (!whisperReceived) {
        throw new Error('Whisper event was not received by peer');
    }
    console.log('✓ Client whisper forwarded to peers on private channel');

    // 4. Presence Channel Join/Leave & 2 Tabs of 1 User
    console.log('\n[E2E 4] Testing Presence Channels (Multiple Sockets per User)...');

    function makePresenceClient(userId, name) {
        return createClient({
            authorizer: (channel) => ({
                authorize: (socketId, callback) => {
                    const userData = JSON.stringify({ user_id: userId, user_info: { name } });
                    const auth = sdk('auth', channel.name, socketId, userData);
                    callback(null, auth);
                }
            })
        });
    }

    const aliceTab1 = makePresenceClient('user-1', 'Alice');
    const aliceTab2 = makePresenceClient('user-1', 'Alice');
    const bob = makePresenceClient('user-2', 'Bob');

    await Promise.all([
        new Promise(res => aliceTab1.connection.bind('connected', res)),
        new Promise(res => aliceTab2.connection.bind('connected', res)),
        new Promise(res => bob.connection.bind('connected', res))
    ]);

    const alicePres1 = aliceTab1.subscribe('presence-room');
    await new Promise(res => alicePres1.bind('pusher:subscription_succeeded', res));

    let bobGotMemberAddedCount = 0;
    let bobGotMemberRemovedCount = 0;

    const bobPres = bob.subscribe('presence-room');
    await new Promise(res => bobPres.bind('pusher:subscription_succeeded', res));

    bobPres.bind('pusher:member_added', () => { bobGotMemberAddedCount++; });
    bobPres.bind('pusher:member_removed', () => { bobGotMemberRemovedCount++; });

    // Alice opens tab 2 (same user)
    const alicePres2 = aliceTab2.subscribe('presence-room');
    await new Promise(res => alicePres2.bind('pusher:subscription_succeeded', res));

    await new Promise(r => setTimeout(r, 300));
    if (bobGotMemberAddedCount !== 0) {
        throw new Error('Bob should not receive member_added when Alice opens a 2nd tab');
    }
    console.log('✓ 2nd tab of same user joined silently (no duplicate member_added)');

    // Check SDK presence queries
    const usersQuery = sdk('get_users', 'presence-room');
    if (!usersQuery.users || usersQuery.users.length !== 2) {
        throw new Error('Expected 2 unique users in presence room, got: ' + JSON.stringify(usersQuery));
    }
    console.log('✓ getPresenceUsers returned correct distinct user count (2)');

    // Alice closes tab 1
    aliceTab1.disconnect();
    await new Promise(r => setTimeout(r, 300));
    if (bobGotMemberRemovedCount !== 0) {
        throw new Error('Bob should not receive member_removed when Alice still has tab 2 connected');
    }
    console.log('✓ Closing 1 of 2 tabs of same user did not trigger member_removed');

    // Alice closes tab 2 (last socket)
    aliceTab2.disconnect();
    await new Promise(r => setTimeout(r, 400));
    if (bobGotMemberRemovedCount !== 1) {
        throw new Error('Bob must receive member_removed when Alice closes her last tab');
    }
    console.log('✓ Closing last tab of user triggered member_removed');

    // 5. Batch Events & SDK Queries
    console.log('\n[E2E 5] Testing Batch Events and SDK Channel Queries...');
    const batchResult = sdk('batch', JSON.stringify([
        { channel: 'public-updates', name: 'batch-1', data: { b: 1 } },
        { channel: 'public-updates', name: 'batch-2', data: { b: 2 } }
    ]));
    if (!batchResult.success) {
        throw new Error('SDK batch events failed');
    }
    console.log('✓ SDK batch events published successfully');

    const channelsQuery = sdk('get_channels');
    if (!channelsQuery.channels || !channelsQuery.channels['public-updates']) {
        throw new Error('Expected public-updates in channels list');
    }
    console.log('✓ getChannels returned active channels list');

    const channelInfo = sdk('get_channel', 'public-updates', 'subscription_count');
    if (!channelInfo.occupied) {
        throw new Error('Expected public-updates to be occupied');
    }
    console.log('✓ getChannelInfo returned correct occupied status');

    // Cleanup
    client1.disconnect();
    client2.disconnect();
    privClient.disconnect();
    badAuthClient.disconnect();
    peerClient.disconnect();
    bob.disconnect();

    console.log('\n=======================================');
    console.log('  ALL E2E TESTS PASSED SUCCESSFULLY!  ');
    console.log('=======================================\n');
    process.exit(0);
}

runTests().catch(err => {
    console.error('E2E TEST FAILURE:', err);
    process.exit(1);
});
