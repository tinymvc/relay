const Pusher = require('pusher-js');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const port = Number(process.env.RELAY_TEST_PORT || 8088);

function sdk(action, ...args) {
    return JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [__dirname + '/e2e_sdk_helper.php', action, ...args], { timeout: 10000 }).toString());
}

function event(target, name) {
    return new Promise(resolve => target.bind(name, resolve));
}

function client() {
    return new Pusher('e2e-key', {
        wsHost: '127.0.0.1', wsPort: port, forceTLS: false,
        enabledTransports: ['ws'], disableStats: true, cluster: 'mt1',
        channelAuthorization: {
            customHandler: ({ socketId, channelName }, callback) => {
                try {
                    callback(null, sdk('auth', channelName, socketId,
                        channelName.startsWith('presence-') ? '{"user_id":"42"}' : ''));
                } catch (error) { callback(error, null); }
            },
        },
    });
}

async function main() {
    let response = await fetch(`http://127.0.0.1:${port}/health`);
    assert.equal(response.status, 200);
    assert.equal(response.headers.get('cache-control'), 'no-store');
    assert.deepEqual(await response.json(), { status: 'ok' });
    response = await fetch(`http://127.0.0.1:${port}/apps/10/stats`);
    assert.equal(response.status, 401);

    sdk('trigger', 'cache-price', 'price', '{"version":1}');
    sdk('trigger', 'cache-price', 'price', '{"version":2}');
    const pusher = client();
    const late = client();
    try {
        await Promise.all([event(pusher.connection, 'connected'), event(late.connection, 'connected')]);
        const publicChannel = pusher.subscribe('cache-price');
        const order = [];
        publicChannel.bind('pusher:subscription_succeeded', () => order.push('subscribed'));
        publicChannel.bind('price', () => order.push('price'));
        assert.deepEqual(await event(publicChannel, 'price'), { version: 2 });
        assert.deepEqual(order, ['subscribed', 'price']);
        pusher.unsubscribe('cache-price');
        // A signed query is a barrier after the unsubscribe has reached the socket.
        for (let attempt = 0; attempt < 20; attempt++) {
            if (!sdk('get_channel', 'cache-price').occupied) break;
            await new Promise(resolve => setTimeout(resolve, 25));
        }
        assert.equal(sdk('get_channel', 'cache-price').occupied, false);
        assert.deepEqual(await event(late.subscribe('cache-price'), 'price'), { version: 2 });
        console.log('✓ Latest cached backend event survives an empty channel and reaches late pusher-js subscribers');

        const empty = pusher.subscribe('cache-empty');
        assert.deepEqual(await event(empty, 'pusher:cache_miss'), {});
        for (const name of ['private-cache-orders', 'presence-cache-team']) {
            sdk('trigger', name, 'snapshot', '{"version":3}');
            assert.deepEqual(await event(pusher.subscribe(name), 'snapshot'), { version: 3 });
        }
        console.log('✓ Cache misses and authorized private/presence cache replay work with native pusher-js');

        const stats = sdk('get_stats');
        assert.equal(stats.connections, 2);
        assert.equal(stats.subscriptions, 4);
        assert.equal(stats.presence_channels, 1);
        assert.equal(stats.cached_channels, 3);
        assert(stats.messages_received >= 5 && stats.messages_sent >= 8);
        assert(stats.bytes_received > 0 && stats.bytes_sent > 0 && stats.cache_bytes > 0);
        console.log('✓ Health and signed PHP SDK statistics report live connections, subscriptions, messages and cache use');
    } finally {
        pusher.disconnect();
        late.disconnect();
    }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
