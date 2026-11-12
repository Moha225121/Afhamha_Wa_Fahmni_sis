// Run against scripts/qa/browser-router.php only. Uses bundled Playwright;
// installs no repository dependency and blocks all non-loopback requests.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(path.join(process.env.QA_NODE_MODULES, 'playwright'));
const base = process.env.QA_BASE_URL || 'http://127.0.0.1:8765';
assert(['http://127.0.0.1:8765', 'http://127.0.0.1:8766'].includes(base));
const output = path.resolve(process.env.QA_EVIDENCE_DIR || 'docs/qa/evidence');
fs.mkdirSync(output, { recursive: true });
const fixture = JSON.parse(fs.readFileSync(path.join(process.env.QA_BROWSER_RUN, 'fixture.json')));
const results = [];
let browser;
async function actor(email, width = 390) {
    const context = await browser.newContext({ viewport: { width, height: 900 }, hasTouch: true, isMobile: width < 768, locale: 'ar-LY', timezoneId: 'Africa/Tripoli', serviceWorkers: 'block' });
    await context.route('**/*', route => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
    const page = await context.newPage();
    page.setDefaultTimeout(12000);
    page.qaErrors = [];
    page.on('pageerror', e => page.qaErrors.push(e.message));
    page.on('response', response => {
        if (response.status() >= 500) page.qaErrors.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`);
    });
    await page.goto(base + '/login');
    await page.locator('[name=email]').fill(email);
    await page.locator('[name=password]').fill('password123');
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click();
    assert(!page.url().endsWith('/login'));
    return page;
}
async function check(name, fn) {
    try { await fn(); results.push({ name, result: 'PASS' }); }
    catch (e) { results.push({ name, result: 'FAIL', detail: e.message.slice(0, 1200) }); }
    console.log(results.at(-1));
}
async function noOverflow(page) {
    const box = await page.evaluate(() => ({ viewport: innerWidth, content: document.documentElement.scrollWidth, dir: document.documentElement.dir }));
    assert.equal(box.dir, 'rtl');
    assert(box.content <= box.viewport + 1, JSON.stringify(box));
}
(async () => {
    browser = await chromium.launch({ headless: true, executablePath: process.env.QA_BROWSER_EXE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
    const admin = await actor('admin@example.test');
    await check('QA009/011 create class by touch, reload, edit by keyboard', async () => {
        await admin.goto(base + '/admin/classes/create');
        await admin.locator('[name=name]').fill('صف متصفح ' + Date.now());
        await admin.locator('[name=stage]').fill('أساسي');
        await admin.locator('[name=section]').fill('أ');
        const checks = admin.locator('[name="subject_ids[]"]');
        await checks.nth(0).tap(); await checks.nth(1).tap();
        await admin.getByRole('button', { name: 'حفظ', exact: true }).click();
        assert(/\/admin\/classes\/\d+$/.test(admin.url()), admin.url());
        const url = admin.url();
        await admin.goto(url + '/edit');
        assert.deepEqual(await checks.evaluateAll(es => es.map(e => e.checked)), [true, true]);
        await checks.nth(0).focus(); await admin.keyboard.press('Space');
        await admin.getByRole('button', { name: 'حفظ', exact: true }).click();
        await admin.goto(url + '/edit');
        assert.deepEqual(await checks.evaluateAll(es => es.map(e => e.checked)), [false, true]);
        await noOverflow(admin);
        await admin.screenshot({ path: path.join(output, 'class-mobile-390.png'), fullPage: true });
    });
    await check('QA010 parent two-child selection persists after reload', async () => {
        await admin.goto(base + '/admin/parents/1/edit');
        const checks = admin.locator('[name="student_ids[]"]');
        assert.equal(await checks.filter({ visible: true }).count() > 1, true);
        assert.equal(await checks.evaluateAll(es => es.filter(e => e.checked).length), 2);
        await checks.nth(0).focus(); await admin.keyboard.press('Space'); await admin.keyboard.press('Space');
        await admin.getByRole('button', { name: 'حفظ', exact: true }).click();
        await admin.goto(base + '/admin/parents/1/edit');
        assert.equal(await checks.evaluateAll(es => es.filter(e => e.checked).length), 2);
    });
    await check('QA001 role labels toggle exactly their own saved permission', async () => {
        await admin.goto(base + '/admin/roles');
        const form = admin.locator('form.role-form').first();
        const title = 'دور متصفح ' + Date.now();
        await form.locator('[name=name]').fill(title);
        const target = form.locator('[name="permissions[]"]').first();
        const value = await target.inputValue();
        await form.locator('label.permission-option').first().tap();
        assert.equal(await form.locator('[name="permissions[]"]').evaluateAll(es => es.filter(e => e.checked).length), 1);
        await form.getByRole('button', { name: 'إنشاء الدور' }).click();
        await admin.reload();
        const card = admin.locator('article.card').filter({ has: admin.getByRole('heading', { name: title, exact: true }) });
        await card.locator('summary').click();
        assert.deepEqual(await card.locator('[name="permissions[]"]').evaluateAll(es => es.filter(e => e.checked).map(e => e.value)), [value]);
        admin.once('dialog', dialog => dialog.accept());
        await card.getByRole('button', { name: /حذف/ }).click();
        await admin.reload();
        assert.equal(await admin.getByRole('heading', { name: title, exact: true }).count(), 0);
    });
    await check('QA017 student finance link and independent setting persistence', async () => {
        await admin.goto(base + '/finance');
        await admin.getByRole('link', { name: 'إعدادات وصول الطالب' }).click();
        assert(admin.url().endsWith('#student-finance-access'));
        const section = admin.locator('#student-finance-access');
        const input = section.locator('[name=student_finance_visible]');
        await input.selectOption('1');
        await section.getByRole('button', { name: 'حفظ وصول الطالب', exact: true }).click();
        await admin.reload();
        assert.equal(await input.inputValue(), '1');
    });
    for (const width of [360, 390, 768, 1440]) {
        await check('QA033/034 admin RTL layout ' + width, async () => {
            await admin.setViewportSize({ width, height: 900 });
            await admin.goto(base + '/admin/conversations');
            await noOverflow(admin);
            assert.equal(await admin.locator('link[href*="school-chat.css"]').count(), 1);
            const sizes = await admin.locator('.school-chat svg').evaluateAll(es => es.map(e => e.getBoundingClientRect().width));
            assert(sizes.every(w => w <= 48), JSON.stringify(sizes));
            if (width < 1024) {
                await admin.locator('#menu').click();
                await admin.waitForFunction(() => {
                    const r = document.getElementById('sidebar').getBoundingClientRect();
                    return r.right <= innerWidth + 1 && r.left >= 0;
                });
                const close = admin.locator('#sidebar-close');
                assert(await close.isVisible());
                const a = await close.boundingBox(), b = await admin.locator('.sidebar .brand-mark').boundingBox();
                assert(a.x >= 0 && a.x + a.width <= width && b.x >= 0 && b.x + b.width <= width);
                assert(a && b && (a.x + a.width <= b.x || b.x + b.width <= a.x || a.y + a.height <= b.y || b.y + b.height <= a.y));
                await admin.screenshot({ path: path.join(output, `admin-menu-${width}.png`), animations: 'disabled' });
                await close.click();
                assert.equal(await admin.locator('#menu').getAttribute('aria-expanded'), 'false');
            }
            await admin.screenshot({ path: path.join(output, `admin-chat-${width}.png`), fullPage: true });
        });
    }
    const student = await actor('student1@example.test');
    await check('QA024/025/035/037 student login, lesson rich text, attachment and navigation', async () => {
        await student.goto(base + `/student/subjects/${fixture.subject}/lessons/${fixture.lesson}`);
        assert((await student.locator('main').innerText()).includes('محتوى درس اصطناعي'));
        const attachment = student.locator('a').filter({ hasText: 'مرفق اصطناعي' }).first();
        const download = await student.request.get(await attachment.getAttribute('href'));
        assert.equal(download.status(), 200);
        assert.equal(await download.text(), 'Synthetic QA attachment');
        for (const width of [360, 390, 768, 1440]) {
            await student.setViewportSize({ width, height: 900 });
            await noOverflow(student);
            await student.screenshot({ path: path.join(output, `student-lesson-${width}.png`), fullPage: true });
        }
    });
    const outsider = await actor('qa-outsider@example.test');
    await check('QA024 server denies unrelated student lesson', async () => {
        const response = await outsider.goto(base + `/student/subjects/${fixture.subject}/lessons/${fixture.lesson}`);
        assert([403, 404].includes(response.status()));
    });
    for (const [email, portal] of [['parent@example.test', 'parent'], ['teacher1@example.test', 'teacher'], ['supervisor@example.test', 'supervisor']]) {
        await check('Regression browser dashboard ' + portal, async () => {
            const page = await actor(email);
            assert.equal((await page.goto(base + `/${portal}/dashboard`)).status(), 200);
            await noOverflow(page);
            assert.deepEqual(page.qaErrors, []);
            await page.context().close();
        });
    }
    await check('QA026 Nasbia manifest names and real icon dimensions', async () => {
        const manifest = await (await admin.request.get(base + '/parent-app.webmanifest')).json();
        assert(manifest.name.includes('النسبية'));
        assert(manifest.short_name.includes('النسبية'));
        assert(manifest.icons.some(i => i.sizes === '192x192'));
    });
    await check('Browser has no uncaught JavaScript errors or HTTP 5xx responses', async () => {
        assert.deepEqual([...admin.qaErrors, ...student.qaErrors, ...outsider.qaErrors], []);
    });
    const report = { testedAt: new Date().toISOString(), browser: browser.version(), environment: 'Loopback isolated synthetic SQLite; real browser, emulated widths/touch; no physical device or push verification', results };
    fs.writeFileSync(path.join(output, 'browser-smoke.json'), JSON.stringify(report, null, 2));
    await browser.close();
    process.exitCode = results.some(x => x.result !== 'PASS') ? 1 : 0;
})().catch(async e => { console.error(e.message); if (browser) await browser.close(); process.exitCode = 1; });
