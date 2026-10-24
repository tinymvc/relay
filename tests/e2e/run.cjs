const { spawn } = require('node:child_process');
const net = require('node:net');
const { once } = require('node:events');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');

async function main() {
    const reservation = net.createServer();
    reservation.listen(0, '127.0.0.1');
    await once(reservation, 'listening');
    const port = reservation.address().port;
    await new Promise(resolve => reservation.close(resolve));
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'relay-e2e-'));
    const env = { ...process.env, RELAY_TEST_PORT: String(port), RELAY_TEST_RESTART_FILE: path.join(temporary, 'restart') };
    const server = spawn(env.PHP_BINARY || 'php', [path.join(__dirname, 'e2e_server.php')], { env });
    let serverLog = '';
    server.stdout.on('data', data => { serverLog = (serverLog + data).slice(-12000); });
    server.stderr.on('data', data => { serverLog = (serverLog + data).slice(-12000); });
    const watchdog = setTimeout(() => {
        console.error('Integration suite exceeded 60 seconds.');
        server.kill('SIGKILL');
        process.exit(1);
    }, 60000);
    try {
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Relay failed to start')), 5000);
            server.once('error', reject);
            server.once('exit', code => { clearTimeout(timer); reject(new Error(`Relay exited early (${code})`)); });
            server.stdout.on('data', data => {
                if (data.toString().includes('E2E Server listening')) {
                    clearTimeout(timer);
                    resolve();
                }
            });
        });
        const scripts = ['e2e_test.cjs', 'features_test.cjs', 'wire_test.cjs'];
        if (process.argv.includes('--load')) scripts.push('load_smoke.cjs');
        const shutdown = once(server, 'exit');
        scripts.push('restart_test.cjs');
        for (const script of scripts) {
            const child = spawn(process.execPath, [path.join(__dirname, script)], { env, stdio: 'inherit' });
            const childTimer = setTimeout(() => child.kill('SIGKILL'), 45000);
            const [code] = await once(child, 'exit');
            clearTimeout(childTimer);
            if (code !== 0) throw new Error(`${script} failed (${code})`);
        }
        const timer = setTimeout(() => server.kill('SIGKILL'), 5000);
        const [code, signal] = await shutdown;
        clearTimeout(timer);
        if (code !== 0 || signal) throw new Error(`Deployment restart failed: ${code}/${signal}`);
        console.log('✓ relay:restart gracefully stops the daemon with exit status 0');
    } catch (error) {
        console.error(serverLog);
        throw error;
    } finally {
        clearTimeout(watchdog);
        fs.rmSync(temporary, { recursive: true, force: true });
        if (server.exitCode === null) {
            const exit = once(server, 'exit');
            server.kill('SIGTERM');
            const timer = setTimeout(() => server.kill('SIGKILL'), 4000);
            const [code, signal] = await exit;
            clearTimeout(timer);
            if (code !== 0 || signal) throw new Error(`Relay did not shut down cleanly: ${code}/${signal}`);
        }
    }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
