const net = require('node:net');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const port = Number(process.env.RELAY_TEST_PORT || 8088);

function exchange(request) {
    return new Promise((resolve, reject) => {
        const socket = net.connect(port, '127.0.0.1', () => socket.write(request));
        const chunks = [];
        socket.setTimeout(4000, () => socket.destroy(new Error('Wire test timed out')));
        socket.on('data', chunk => chunks.push(chunk));
        socket.on('error', reject);
        socket.on('end', () => resolve(Buffer.concat(chunks)));
    });
}

function handshake() {
    return Buffer.from('GET /app/e2e-key?protocol=7 HTTP/1.1\r\nHost: localhost\r\nConnection: Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n');
}

function frames(buffer) {
    let offset = buffer.indexOf('\r\n\r\n') + 4;
    const result = [];
    while (offset < buffer.length) {
        const opcode = buffer[offset] & 15;
        let length = buffer[offset + 1] & 127;
        offset += 2;
        if (length === 126) { length = buffer.readUInt16BE(offset); offset += 2; }
        if (length === 127) { length = Number(buffer.readBigUInt64BE(offset)); offset += 8; }
        result.push({ opcode, payload: buffer.subarray(offset, offset + length) });
        offset += length;
    }
    return result;
}

async function main() {
    let response = await exchange('POST /apps/10/events HTTP/1.1\r\nHost: localhost\r\nContent-Length: 999999999\r\nExpect: 100-continue\r\n\r\n');
    assert.match(response.toString(), /^HTTP\/1.1 413/);
    assert(!response.includes('100 Continue'));
    response = await exchange('GET / HTTP/1.1\r\nX-Fill: ' + 'x'.repeat(17000));
    assert.match(response.toString(), /^HTTP\/1.1 431/);
    response = await exchange('POST /apps/10/events HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\nContent-Length: 1\r\n\r\n');
    assert.match(response.toString(), /^HTTP\/1.1 400/);
    response = await exchange('GET /incomplete');
    assert.equal(response.length, 0);
    console.log('✓ Real TCP requests enforce header/body limits, framing and timeout');

    // Send upgrade and frame in one write; only the frame header and a small prefix arrive.
    const partial = Buffer.alloc(140);
    partial[0] = 0x81;
    partial[1] = 0xfe;
    partial.writeUInt16BE(11000, 2);
    response = await exchange(Buffer.concat([handshake(), partial]));
    let parsed = frames(response);
    assert.equal(parsed.filter(f => f.opcode === 8).length, 1);
    assert.equal(parsed.find(f => f.opcode === 8).payload.readUInt16BE(), 1009);
    response = await exchange(Buffer.concat([handshake(), Buffer.from([0x81, 2, 0x7b, 0x7d])]));
    parsed = frames(response);
    assert.equal(parsed.filter(f => f.opcode === 8).length, 1);
    assert.equal(parsed.find(f => f.opcode === 8).payload.readUInt16BE(), 1002);
    console.log('✓ Real WebSocket framing rejects oversized and unmasked messages with one close frame');

    const params = { auth_key: 'e2e-key', auth_timestamp: String(Math.floor(Date.now() / 1000)), auth_version: '1.0' };
    const canonical = Object.keys(params).sort().map(key => `${key}=${params[key]}`).join('&');
    params.auth_signature = crypto.createHmac('sha256', 'e2e-secret').update(`GET\n/apps/10/channels\n${canonical}`).digest('hex');
    response = await exchange(`GET /apps/10/channels?${new URLSearchParams(params)} HTTP/1.1\r\nHost: localhost\r\n\r\n`);
    assert.match(response.toString(), /^HTTP\/1.1 200/);
    assert.deepEqual(JSON.parse(response.toString().split('\r\n\r\n')[1]), { channels: {} });
    console.log('✓ Daemon remains healthy and disconnected clients leave no channels');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
