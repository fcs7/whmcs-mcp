// Optional native-browser integration check. See docs/WEBMCP.md for prerequisites.
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const net = require('node:net');
const path = require('node:path');
const { chromium } = require(process.env.WEBMCP_PLAYWRIGHT_MODULE || 'playwright-core');

(async () => {
    const reservation = net.createServer();
    await new Promise((resolve, reject) => {
        reservation.once('error', reject);
        reservation.listen(0, '127.0.0.1', resolve);
    });
    const port = reservation.address().port;
    await new Promise(resolve => reservation.close(resolve));
    const origin = 'http://127.0.0.1:' + port;
    const server = spawn(process.env.WEBMCP_PHP_BIN || 'php', [
        '-S', '127.0.0.1:' + port, path.join(__dirname, 'fixtures/browser-router.php'),
    ], { stdio: 'ignore' });
    let serverError;
    server.on('error', error => { serverError = error; });
    const report = [];
    let browser;

    try {
        for (let attempt = 0; ; attempt++) {
            if (serverError) throw serverError;
            if (server.exitCode !== null) throw new Error('Local PHP fixture server exited before startup');
            try {
                const response = await fetch(origin, { signal: AbortSignal.timeout(1000) });
                assert.equal(response.status, 200, 'Local PHP fixture must render successfully');
                break;
            } catch (error) {
                if (attempt >= 50) throw error;
                await new Promise(resolve => setTimeout(resolve, 100));
            }
        }
        for (const enabled of [false, true]) {
            browser = await chromium.launch({
                executablePath: process.env.WEBMCP_CHROMIUM_BIN || undefined,
                headless: true,
                args: [(enabled ? '--enable' : '--disable') + '-blink-features=WebMCP'],
            });
            const page = await browser.newPage();
            const errors = [];
            const requests = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('request', request => requests.push({ url: request.url(), method: request.method() }));
            const response = await page.goto(origin + '/hospedagem.php?enabled=1');
            await page.waitForLoadState('networkidle');
            assert.equal(response.status(), 200);
            assert.equal(response.headers()['x-smoke-sdk-loaded'], '0');
            const state = await page.evaluate(async () => ({
                bootstrap: window.NTWebMCP,
                native: typeof document.modelContext?.registerTool === 'function',
                tools: typeof document.modelContext?.getTools === 'function'
                    ? (await document.modelContext.getTools()).length : null,
            }));
            assert.equal(state.bootstrap.toolCount, 0);
            assert.equal(state.bootstrap.status, enabled ? 'ready' : 'unsupported');
            assert.equal(state.native, enabled, 'The native feature flag must change API availability');
            if (enabled) assert.equal(state.tools, 0);
            assert.equal(requests.length, 2, 'Only the page and static asset may be requested');
            assert.ok(requests.every(request => new URL(request.url).origin === origin && request.method === 'GET'));
            assert.deepEqual(errors, []);
            assert.equal((await page.content()).includes('fixture-csrf-must-not-be-exported'), false);
            report.push({ scenario: enabled ? 'native-enabled' : 'native-disabled', browser: browser.version(), ...state });

            for (const query of [
                '', '?enabled=0', '?enabled=yes', '?enabled=1&auth=user', '?enabled=1&auth=admin',
                '?enabled=1&auth=client', '?enabled=1&landing=cloud', '?enabled=1&theme=twenty-one',
                '?enabled=1&marker=missing', '?enabled=1&ssl=0', '?enabled=1&webroot=//outside.example',
            ]) {
                requests.length = 0;
                await page.goto(origin + '/hospedagem.php' + query);
                assert.equal(await page.locator('[data-nt-mcp-webmcp]').count(), 0);
                assert.equal(await page.evaluate(() => typeof window.NTWebMCP), 'undefined');
                assert.equal(requests.length, 1, 'An excluded page must not request the asset');
                assert.deepEqual(errors, []);
                report.push({ scenario: 'excluded', nativeEnabled: enabled, query });
            }
            await browser.close();
            browser = null;
        }
        console.log(JSON.stringify({ passed: true, scenarios: report.length, report }, null, 2));
    } finally {
        try {
            if (browser) await browser.close();
        } finally {
            server.kill();
        }
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
