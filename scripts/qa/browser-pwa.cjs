const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(path.join(process.env.QA_NODE_MODULES, 'playwright'));
const base = process.env.QA_BASE_URL || 'http://127.0.0.1:8765';
assert(['http://127.0.0.1:8765', 'http://127.0.0.1:8766'].includes(base));
const output = path.resolve(process.env.QA_EVIDENCE_DIR || 'docs/qa/evidence');
const results = [];
let browser;
const publicPaths = new Set([
    '/parent-offline.html', '/student-offline.html', '/favicon.ico', '/icons/nasbia-logo.jpg',
    '/school-branding/icon-180.png', '/school-branding/icon-192.png', '/school-branding/icon-512.png',
    '/school-app-icon', '/parent-app.webmanifest', '/parent-manifest.webmanifest', '/student-manifest.webmanifest',
]);
async function cachesOn(page) {
    return page.evaluate(async () => {
        const inventory = [];
        for (const name of await caches.keys()) {
            const cache = await caches.open(name);
            const entries = [];
            for (const request of await cache.keys()) {
                const response = await cache.match(request);
                entries.push({ url: request.url, method: request.method, status: response.status, type: response.headers.get('content-type') });
            }
            inventory.push({ name, entries });
        }
        return inventory;
    });
}
function assertPublicOnly(inventory, portal) {
    assert.equal(inventory.length, 1, 'A fresh context must contain only the selected portal cache');
    assert(inventory[0].name.startsWith('afhamha-' + portal + '-static-'));
    assert(inventory[0].entries.length > 0);
    assert(inventory[0].entries.some(item => new URL(item.url).pathname === '/' + portal + '-offline.html'));
    for (const item of inventory[0].entries) {
        const url = new URL(item.url);
        assert.equal(url.origin, base);
        assert(publicPaths.has(url.pathname), 'Unexpected cache key: ' + url.pathname);
        assert.equal(item.method, 'GET');
        assert.equal(item.status, 200, 'Unsuccessful precached response: ' + url.pathname);
        assert(!/^\/(parent|student)\//.test(url.pathname), 'Account route must never be cached');
    }
}
async function inspectPortal(portal) {
    const context = await browser.newContext({ viewport: { width: 390, height: 900 }, locale: 'ar-LY', timezoneId: 'Africa/Tripoli', serviceWorkers: 'allow' });
    const blockedExternal = [], unsuccessfulOnline = [];
    let offline = false;
    // The checked-in workers import no external scripts and cache only same-origin public assets.
    // A loopback-only proxy below provides a second barrier for requests outside routing coverage.
    await context.route('**/*', route => {
        if (new URL(route.request().url()).origin === base) return route.continue();
        blockedExternal.push(route.request().url()); return route.abort();
    });
    context.on('response', response => {
        if (!offline && response.status() >= 400) unsuccessfulOnline.push({ url: response.url(), status: response.status() });
    });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    const record = { name: portal + ' worker actual activation, offline fallback, public cache only', result: 'FAIL' };
    try {
        await page.goto(base + '/' + portal + '-offline.html');
        assert.deepEqual(await page.evaluate(() => caches.keys()), []);
        const activation = await page.evaluate(async ({ portal }) => {
            const registration = await navigator.serviceWorker.register('/' + portal + '-sw.js', { scope: '/' + portal + '/' });
            const limit = Date.now() + 15000;
            while (registration.active?.state !== 'activated') {
                if (Date.now() > limit || registration.installing?.state === 'redundant') {
                    throw new Error('Service worker did not activate; inspect cache.addAll asset failures.');
                }
                await new Promise(resolve => setTimeout(resolve, 100));
            }
            return { scope: registration.scope, script: registration.active.scriptURL, state: registration.active.state };
        }, { portal });
        assert.equal(activation.scope, base + '/' + portal + '/');
        assert.equal(activation.state, 'activated');
        const before = await cachesOn(page);
        assertPublicOnly(before, portal);
        assert.deepEqual(unsuccessfulOnline, []);
        offline = true;
        await context.setOffline(true);
        const offlineNavigations = [];
        for (const destination of ['qa-offline-check', 'dashboard', 'grades', 'messages']) {
            const response = await page.goto(base + '/' + portal + '/' + destination, { waitUntil: 'domcontentloaded' });
            assert.equal(response.status(), 200);
            assert(response.fromServiceWorker(), 'Offline response must actually be supplied by the worker');
            await page.getByRole('heading', { name: 'أنت غير متصل حالياً', exact: true }).waitFor();
            const controller = await page.evaluate(() => navigator.serviceWorker.controller?.scriptURL);
            assert.equal(controller, activation.script);
            offlineNavigations.push({ path: '/' + portal + '/' + destination, status: response.status(), fromServiceWorker: response.fromServiceWorker() });
        }
        const after = await cachesOn(page);
        assertPublicOnly(after, portal);
        assert.deepEqual(after, before, 'Navigation must not add account routes or responses to CacheStorage');
        await page.screenshot({ path: path.join(output, 'pwa-' + portal + '-offline.png'), fullPage: true });
        record.result = 'PASS';
        Object.assign(record, { activation, offlineNavigations, cache: after, blockedExternal, unsuccessfulOnline });
    } catch (error) {
        Object.assign(record, { detail: error.message.slice(0, 1800), blockedExternal, unsuccessfulOnline });
    } finally {
        results.push(record); console.log(JSON.stringify(record));
        await context.close();
    }
}
(async () => {
    fs.mkdirSync(output, { recursive: true });
    browser = await chromium.launch({ headless: true, executablePath: process.env.QA_BROWSER_EXE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', proxy: { server: 'http://127.0.0.1:9', bypass: '127.0.0.1' } });
    await inspectPortal('parent');
    await inspectPortal('student');
    fs.writeFileSync(path.join(output, 'browser-pwa.json'), JSON.stringify({
        testedAt: new Date().toISOString(), browser: browser.version(), viewport: '390x900',
        environment: 'Loopback synthetic QA server; clean unauthenticated contexts; service workers allowed; external requests blocked',
        limits: 'Actual local worker installation/activation and offline navigation only. No physical-device PWA install/update, Push, external provider, or authenticated/private account data tested.',
        results,
    }, null, 2));
    await browser.close(); process.exitCode = results.some(item => item.result !== 'PASS') ? 1 : 0;
})().catch(async error => { console.error(error.message); if (browser) await browser.close(); process.exitCode = 1; });
