const Pusher = require('pusher-js');
const { execFileSync } = require('child_process');

async function runLoadSmoke() {
    console.log('--- Relay Load Smoke Test ---');

    // On Windows with StreamSelectLoop, test safe limit (e.g. 100 concurrent sockets)
    const targetConnections = 100;
    const clients = [];

    console.log(`Connecting ${targetConnections} concurrent clients...`);
    const startConnect = Date.now();

    for (let i = 0; i < targetConnections; i++) {
        const client = new Pusher('e2e-key', {
            wsHost: '127.0.0.1',
            wsPort: Number(process.env.RELAY_TEST_PORT || 8088),
            forceTLS: false,
            enabledTransports: ['ws'],
            disableStats: true,
            cluster: 'mt1'
        });
        clients.push(client);
    }

    await Promise.all(clients.map(c => new Promise(res => c.connection.bind('connected', res))));
    console.log(`✓ All ${targetConnections} clients connected in ${Date.now() - startConnect} ms`);

    console.log('Subscribing all clients to channel "load-test"...');
    let totalMessagesReceived = 0;
    const targetMessages = 50;

    await Promise.all(clients.map(c => {
        const ch = c.subscribe('load-test');
        ch.bind('load-event', () => {
            totalMessagesReceived++;
        });
        return new Promise(res => ch.bind('pusher:subscription_succeeded', res));
    }));
    console.log('✓ All clients subscribed');

    console.log(`Publishing ${targetMessages} events via HTTP API...`);
    const startPublish = Date.now();

    for (let i = 0; i < targetMessages; i++) {
        const payload = JSON.stringify({ seq: i, time: Date.now() });
        execFileSync(process.env.PHP_BINARY || 'php', [__dirname + '/e2e_sdk_helper.php', 'trigger', 'load-test', 'load-event', payload], { timeout: 10000 });
    }

    const publishDuration = Date.now() - startPublish;
    console.log(`✓ Published ${targetMessages} events in ${publishDuration} ms (${((targetMessages / (publishDuration / 1000))).toFixed(1)} msg/s)`);

    await new Promise(r => setTimeout(r, 1000));
    const expectedMessages = targetConnections * targetMessages;
    console.log(`Total messages received across all sockets: ${totalMessagesReceived} / ${expectedMessages}`);

    if (totalMessagesReceived !== expectedMessages) {
        throw new Error(`Message loss detected! Expected ${expectedMessages}, got ${totalMessagesReceived}`);
    }

    console.log(`✓ 100% delivery rate verified (${totalMessagesReceived} deliveries)`);

    clients.forEach(c => c.disconnect());
    console.log('✓ Load smoke test finished cleanly');
    process.exit(0);
}

runLoadSmoke().catch(e => {
    console.error('Load smoke failed:', e);
    process.exit(1);
});
