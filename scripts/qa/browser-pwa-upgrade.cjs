const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const crypto = require('node:crypto');
const { chromium } = require(path.join(process.env.QA_NODE_MODULES, 'playwright'));
const base = process.env.QA_BASE_URL || 'http://127.0.0.1:8765';
assert(['http://127.0.0.1:8765', 'http://127.0.0.1:8766'].includes(base));
const run = fs.realpathSync(process.env.QA_BROWSER_RUN);
assert(run.startsWith(fs.realpathSync('tmp/qa-browser') + path.sep));
const output = path.resolve(process.env.QA_EVIDENCE_DIR || 'docs/qa/evidence');
const stateFile = path.join(run, 'pwa-upgrade-state.json');
const results = [];
let browser;
const startedAt = new Date().toISOString();
const publicPaths = new Set(['/parent-offline.html', '/student-offline.html', '/offline.html', '/favicon.ico', '/icons/nasbia-logo.jpg', '/school-branding/icon-180.png', '/school-branding/icon-192.png', '/school-branding/icon-512.png', '/school-app-icon', '/parent-app.webmanifest', '/student-manifest.webmanifest', '/manifest.webmanifest']);
function phase(portal, value) { fs.writeFileSync(stateFile, JSON.stringify({ portal, phase: value })); }
function workerMetadata(file) {
    const source = fs.readFileSync(file, 'utf8');
    return { cache: vm.runInNewContext(source + '\nCACHE_NAME', { self: { addEventListener() {} }, importScripts() {} }), sha256: crypto.createHash('sha256').update(source).digest('hex') };
}
async function login(page, email) {
    await page.goto(base + '/login');
    await page.locator('[name=email]').fill(email);
    await page.locator('[name=password]').fill('password123');
    await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
    assert(!page.url().endsWith('/login'));
}
async function cacheInventory(page, privateTexts = []) {
    const inventory = await page.evaluate(async () => {
        const result = [];
        for (const name of await caches.keys()) {
            const cache = await caches.open(name), entries = [];
            for (const request of await cache.keys()) {
                const response = await cache.match(request);
                entries.push({ path: new URL(request.url).pathname, status: response.status, method: request.method, body: (response.headers.get('content-type') || '').includes('text/html') ? await response.text() : '' });
            }
            result.push({ name, entries });
        }
        return result;
    });
    for (const cache of inventory) for (const entry of cache.entries) {
        assert(publicPaths.has(entry.path), 'Private/unexpected cache key: ' + entry.path);
        assert.equal(entry.status, 200); assert.equal(entry.method, 'GET');
        for (const text of privateTexts) assert(!entry.body.includes(text), 'Private identity leaked into public cached HTML');
        delete entry.body;
    }
    return inventory;
}
async function testPortal(portal) {
    const old = workerMetadata(`scripts/qa/fixtures/${portal}-sw-baseline.js`);
    const current = workerMetadata(`public/${portal}-sw.js`);
    const errors = [], expectedDenied = [];
    const context = await browser.newContext({ viewport: { width: 390, height: 900 }, hasTouch: true, locale: 'ar-LY', timezoneId: 'Africa/Tripoli', serviceWorkers: 'allow' });
    await context.route('**/*', r => new URL(r.request().url()).origin === base ? r.continue() : r.abort());
    context.on('response', r => { if (r.status() >= 500) errors.push({ path: new URL(r.url()).pathname, status: r.status() }); });
    const page = await context.newPage(); page.setDefaultTimeout(20000);
    page.on('pageerror', error => errors.push({ pageerror: error.message }));
    const record = { name: `${portal}: baseline-to-current upgrade, unsaved form, logout and account switch`, result: 'FAIL', old, current };
    try {
        phase(portal, 'baseline');
        await page.goto(base + `/${portal}-offline.html`);
        await page.evaluate(async ({ portal }) => {
            const reg = await navigator.serviceWorker.register(`/${portal}-sw.js`, { scope: `/${portal}/`, updateViaCache: 'none' });
            const limit = Date.now() + 20000;
            while (reg.active?.state !== 'activated') {
                if (Date.now() > limit) throw new Error('Baseline worker did not activate.');
                await new Promise(resolve => setTimeout(resolve, 100));
            }
        }, { portal });
        assert((await page.evaluate(() => caches.keys())).includes(old.cache));
        const accountA = portal === 'parent' ? 'parent@example.test' : 'student1@example.test';
        const accountB = portal === 'parent' ? 'qa-parent-one@example.test' : 'student2@example.test';
        await login(page, accountA);
        let privateUrl;
        if (portal === 'parent') {
            await page.goto(base + '/parent/children');
            const children = await page.locator('a[href*="/parent/children/"]').evaluateAll(es => [...new Set(es.map(e => e.href))]);
            assert(children.length >= 2);
            privateUrl = children.at(-1);
            await page.goto(base + '/parent/profile');
        } else {
            await page.goto(base + '/student/tutor');
            await page.getByRole('button', { name: 'محادثة جديدة', exact: true }).click();
            assert(/\/student\/tutor\/conversations\/\d+$/.test(page.url()));
            privateUrl = page.url();
        }
        const field = portal === 'parent' ? '[name=name]' : '[name=message]';
        const draft = 'QA مسودة غير محفوظة أثناء تحديث العامل';
        await page.locator(field).fill(draft);
        const before = await page.evaluate(() => { window.__qaUpgradeMarker = 'same-document'; return { timeOrigin: performance.timeOrigin, url: location.href, controller: navigator.serviceWorker.controller?.scriptURL }; });
        assert(before.controller?.endsWith(`/${portal}-sw.js`));
        let navigations = 0;
        const observeNavigation = frame => { if (frame === page.mainFrame()) navigations++; };
        page.on('framenavigated', observeNavigation);
        phase(portal, 'current');
        await page.evaluate(async ({ portal, cacheName }) => {
            const reg = await navigator.serviceWorker.getRegistration(`/${portal}/`), oldWorker = reg.active;
            await reg.update();
            const limit = Date.now() + 20000;
            while (reg.active === oldWorker || reg.active?.state !== 'activated' || !(await caches.keys()).includes(cacheName)) {
                if (Date.now() > limit) throw new Error('Current worker did not replace baseline worker.');
                await new Promise(resolve => setTimeout(resolve, 100));
            }
        }, { portal, cacheName: current.cache });
        const after = await page.evaluate(() => ({ timeOrigin: performance.timeOrigin, url: location.href, marker: window.__qaUpgradeMarker, controller: navigator.serviceWorker.controller?.scriptURL }));
        assert.equal(after.timeOrigin, before.timeOrigin); assert.equal(after.url, before.url);
        assert.equal(after.marker, 'same-document'); assert.equal(await page.locator(field).inputValue(), draft);
        assert.equal(navigations, 0, 'Upgrade must not force page reload or navigate away from unsaved form');
        page.off('framenavigated', observeNavigation);
        const names = await page.evaluate(() => caches.keys());
        assert(!names.includes(old.cache)); assert(names.includes(current.cache));
        const active = await page.evaluate(async portal => {
            const reg = await navigator.serviceWorker.getRegistration(`/${portal}/`);
            return { scope: reg.scope, script: reg.active.scriptURL, state: reg.active.state };
        }, portal);
        assert.equal(active.scope, `${base}/${portal}/`);
        const beforeLogout = await cacheInventory(page, [draft, 'أحمد التجريبي', 'سارة التجريبية']);
        await page.screenshot({ path: path.join(output, `pwa-upgrade-${portal}-draft.png`), fullPage: true });
        await page.locator('form[action$="/logout"]').first().evaluate(form => form.requestSubmit());
        await page.waitForURL('**/login');
        await page.goBack({ waitUntil: 'domcontentloaded' });
        assert(page.url().endsWith('/login'), 'Back navigation after logout must not restore authenticated account page');
        await login(page, accountB);
        const denied = await page.goto(privateUrl);
        assert([403, 404].includes(denied.status()), 'Another account must not open the prior private resource');
        expectedDenied.push({ path: new URL(privateUrl).pathname, status: denied.status() });
        await page.goto(base + `/${portal}/profile`);
        const profile = await page.locator('main').innerText();
        if (portal === 'student') { assert(profile.includes('سارة التجريبية')); assert(!profile.includes('أحمد التجريبي')); }
        else { assert(!profile.includes(draft)); }
        const afterSwitch = await cacheInventory(page, [draft, 'أحمد التجريبي', 'سارة التجريبية']);
        await context.setOffline(true);
        const offline = await page.goto(privateUrl, { waitUntil: 'domcontentloaded' });
        assert(offline.fromServiceWorker()); assert.equal(offline.status(), 200);
        await page.getByRole('heading', { name: 'أنت غير متصل حالياً', exact: true }).waitFor();
        const body = await page.locator('body').innerText();
        assert(!body.includes('أحمد التجريبي') && !body.includes('سارة التجريبية') && !body.includes(draft));
        const afterOffline = await cacheInventory(page, [draft, 'أحمد التجريبي', 'سارة التجريبية']);
        assert.deepEqual(afterOffline, afterSwitch);
        assert.deepEqual(errors, []);
        Object.assign(record, { result: 'PASS', active, upgrade: { forcedNavigations: navigations, sameDocument: true, draftPreserved: true }, accountSwitch: { oldResourceDenied: expectedDenied, offlineGeneric: true, historyBackRequiresLogin: true }, cache: { beforeLogout, afterSwitch, afterOffline }, errors });
    } catch (error) { Object.assign(record, { detail: error.message.slice(0, 1700), errors, expectedDenied }); }
    finally { phase(portal, 'current'); results.push(record); console.log(JSON.stringify(record)); await context.close(); }
}
(async () => {
    fs.mkdirSync(output, { recursive: true });
    browser = await chromium.launch({ headless: true, executablePath: process.env.QA_BROWSER_EXE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', proxy: { server: 'http://127.0.0.1:9', bypass: '127.0.0.1' } });
    await testPortal('parent'); await testPortal('student');
    fs.writeFileSync(path.join(output, 'browser-pwa-upgrade.json'), JSON.stringify({ startedAt, testedAt: new Date().toISOString(), browser: browser.version(), origin: base, fixture: path.basename(run), environment: 'Clean local browser contexts, isolated synthetic database, actual worker upgrade and account switching; external network blocked', baseline: 'Workers copied from HEAD 1a5cfe27c72e38acd05c837682a3f29b0d7b3402. Missing historic icon assets supplied by explicit QA-only approved PNG shim.', limits: 'Not a physical-device installation/update, real Push or historic artifact equivalence test.', results }, null, 2));
    await browser.close(); process.exitCode = results.some(r => r.result !== 'PASS') ? 1 : 0;
})().catch(async error => { console.error(error.message); if (browser) await browser.close(); process.exitCode = 1; });
