const Pusher = require('pusher-js');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

async function main() {
    const pusher = new Pusher('e2e-key', {
        wsHost: '127.0.0.1', wsPort: Number(process.env.RELAY_TEST_PORT),
        forceTLS: false, enabledTransports: ['ws'], disableStats: true, cluster: 'mt1',
    });
    try {
        await new Promise(resolve => pusher.connection.bind('connected', resolve));
        const closed = new Promise(resolve => pusher.connection.connection.transport.bind('closed', resolve));
        const reconnecting = new Promise(resolve => pusher.connection.bind('connecting', resolve));
        execFileSync(process.env.PHP_BINARY || 'php', [__dirname + '/e2e_sdk_helper.php', 'restart'], { timeout: 10000 });
        const close = await closed;
        assert.equal(close.code, 4200);
        assert.equal(close.reason, 'Deployment restart');
        // Let pusher-js exercise its automatic retry before cancelling the test client.
        await reconnecting;
        console.log('✓ Deployment restart sends pusher-js the reconnect close code 4200');
    } finally {
        pusher.disconnect();
    }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
