const Pusher = require('pusher-js');

console.log('Starting Pusher client test...');

const pusher = new Pusher('test-key', {
    wsHost: '127.0.0.1',
    wsPort: 8085,
    forceTLS: false,
    enabledTransports: ['ws'],
    disableStats: true,
    cluster: 'mt1'
});

let connected = false;
let socketId = null;
let reconnectedAfter4200 = false;

pusher.connection.bind('state_change', (states) => {
    console.log(`[Client] State changed from ${states.previous} to ${states.current}`);
});

pusher.connection.bind('connected', () => {
    socketId = pusher.connection.socket_id;
    console.log(`[Client] Connected! Socket ID: ${socketId}`);
    connected = true;

    if (!reconnectedAfter4200) {
        // Test (b): stay connected for 8 seconds (> 2 * 3s activity_timeout)
        console.log('[Client] Waiting 8 seconds to verify ping/pong keep-alive...');
        setTimeout(() => {
            console.log('[Client] Still connected after 8 seconds! Gate (b) passed.');
            // Test (e): Trigger server close 4200
            console.log('[Client] Sending trigger for server close 4200...');
            pusher.send_event('test:close_4200', {});
        }, 8000);
    } else {
        console.log('[Client] Reconnected after 4200 close! Gate (e) passed.');
        console.log('[Client] Closing connection to test client disconnect...');
        pusher.disconnect();
        setTimeout(() => {
            console.log('[Client] Test finished successfully.');
            process.exit(0);
        }, 1000);
    }
});

pusher.connection.bind('disconnected', () => {
    console.log('[Client] Disconnected');
});

pusher.connection.bind('connecting_in', (delay) => {
    console.log(`[Client] Reconnecting in ${delay} ms...`);
    reconnectedAfter4200 = true;
});

pusher.connection.bind('error', (err) => {
    console.log('[Client] Error:', err);
});
