const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(path.join(process.env.QA_NODE_MODULES, 'playwright'));
const base = 'http://127.0.0.1:8765';
const output = path.resolve(process.env.QA_EVIDENCE_DIR || 'docs/qa/evidence');
fs.mkdirSync(output, { recursive: true });
const results = [], errors = [], blockedExternal = [];
let browser;
async function actor(email) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 900 }, locale: 'ar-LY', timezoneId: 'Africa/Tripoli', serviceWorkers: 'block' });
    await ctx.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.origin === base) return route.continue();
        blockedExternal.push(url.origin);
        return route.abort();
    });
    const page = await ctx.newPage(); page.setDefaultTimeout(12000);
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => {
        if (response.status() >= 500) errors.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`);
    });
    await page.goto(base + '/login');
    await page.locator('[name=email]').fill(email);
    await page.locator('[name=password]').fill('password123');
    await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
    assert(!page.url().endsWith('/login'));
    return page;
}
async function check(name, run) {
    try { await run(); results.push({ name, result: 'PASS' }); }
    catch (error) { results.push({ name, result: 'FAIL', detail: error.message.slice(0, 1000) }); }
    console.log(results.at(-1));
}
(async () => {
    browser = await chromium.launch({ headless: true, executablePath: process.env.QA_BROWSER_EXE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
    const teacher = await actor('teacher1@example.test');
    const student = await actor('student1@example.test');
    const parent = await actor('parent@example.test');
    let teacherId, studentId;
    await check('QA007 teacher entry, repeated creation and reload preserve one owned conversation', async () => {
        await teacher.goto(base + '/teacher/tutor');
        await teacher.getByRole('heading', { name: 'المساعد التعليمي', exact: true }).waitFor();
        const form = teacher.locator('.teacher-tutor form[data-tutor-form]');
        const requestId = await form.locator('[name=request_id]').inputValue();
        const csrf = await form.locator('[name=_token]').inputValue();
        let creationRequests = 0;
        teacher.on('request', request => { if (request.method() === 'POST' && request.url() === base + '/teacher/tutor/conversations') creationRequests++; });
        await Promise.all([
            teacher.waitForURL(/\/teacher\/tutor\/conversations\/\d+$/),
            form.getByRole('button', { name: 'محادثة جديدة', exact: true }).evaluate(button => { button.click(); button.click(); }),
        ]);
        assert.equal(creationRequests, 1);
        teacherId = teacher.url().match(/conversations\/(\d+)/)[1];
        const replay = await teacher.context().request.post(base + '/teacher/tutor/conversations', {
            form: { _token: csrf, request_id: requestId }, maxRedirects: 0,
        });
        assert.equal(replay.status(), 302);
        assert.equal(new URL(replay.headers().location).pathname, '/teacher/tutor/conversations/' + teacherId);
        await teacher.reload();
        await teacher.getByRole('heading', { name: 'ابدأ بالسؤال', exact: true }).waitFor();
        await teacher.goto(base + '/teacher/tutor');
        assert.equal(await teacher.locator(`.tutor-conversation[href$="/conversations/${teacherId}"]`).count(), 1);
        await teacher.goto(base + '/teacher/tutor/conversations/' + teacherId);
    });
    await check('QA007 missing provider shows safe failure; message persists and user markup cannot execute', async () => {
        assert(teacherId);
        const question = '<img src=x onerror="window.qaTutorXss=1"> سؤال معلم اصطناعي ' + Date.now();
        const form = teacher.locator('.chat-composer');
        const requestId = await form.locator('[name=request_id]').inputValue();
        const csrf = await form.locator('[name=_token]').inputValue();
        await form.locator('[name=message]').fill(question);
        await Promise.all([teacher.waitForURL(teacher.url()), form.getByRole('button', { name: 'إرسال السؤال', exact: true }).click()]);
        await teacher.getByText('خدمة المساعد التعليمي غير مهيأة حاليًا.', { exact: false }).waitFor();
        assert.equal(await teacher.locator('[data-tutor-message-content]').filter({ hasText: question }).count(), 1);
        assert.equal(await teacher.locator('.chat-history img').count(), 0);
        assert.equal(await teacher.evaluate(() => window.qaTutorXss), undefined);
        const replay = await teacher.context().request.post(base + '/teacher/tutor/conversations/' + teacherId + '/messages', {
            form: { _token: csrf, request_id: requestId, message: question }, maxRedirects: 0,
        });
        assert.equal(replay.status(), 302);
        await teacher.reload();
        assert.equal(await teacher.locator('[data-tutor-message-content]').filter({ hasText: question }).count(), 1);
        assert.equal(await teacher.locator('.chat-message.from-tutor').count(), 0);
        for (const width of [390, 1440]) {
            await teacher.setViewportSize({ width, height: 900 });
            await teacher.evaluate(async () => { await document.fonts.ready; });
            assert(await teacher.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
            await teacher.screenshot({ path: path.join(output, `teacher-tutor-${width}.png`), fullPage: true, animations: 'disabled' });
        }
    });
    await check('QA007 teacher, student and parent remain isolated across both conversation URL families', async () => {
        assert(teacherId);
        await student.goto(base + '/student/tutor');
        await Promise.all([
            student.waitForURL(/\/student\/tutor\/conversations\/\d+$/),
            student.getByRole('button', { name: 'محادثة جديدة', exact: true }).click(),
        ]);
        studentId = student.url().match(/conversations\/(\d+)/)[1];
        await student.reload();
        await student.getByRole('heading', { name: 'ابدأ بالسؤال', exact: true }).waitFor();
        const question = 'سؤال طالب اصطناعي محفوظ ' + Date.now();
        const form = student.locator('.chat-composer');
        await form.locator('[name=message]').fill(question);
        await Promise.all([student.waitForURL(student.url()), form.getByRole('button', { name: 'إرسال السؤال', exact: true }).click()]);
        await student.getByText('غير مهيأة', { exact: false }).waitFor();
        await student.reload();
        assert.equal(await student.locator('[data-tutor-message-content]').filter({ hasText: question }).count(), 1);
        assert.equal(await student.locator('.chat-message.from-tutor').count(), 0);
        await student.goto(base + '/student/tutor');
        assert.equal(await student.locator(`.list-row[href$="/conversations/${studentId}"]`).count(), 1);
        assert.equal((await teacher.goto(base + '/teacher/tutor/conversations/' + studentId)).status(), 404);
        assert.equal((await student.goto(base + '/student/tutor/conversations/' + teacherId)).status(), 404);
        assert.equal((await student.goto(base + '/teacher/tutor/conversations/' + teacherId)).status(), 403);
        assert.equal((await parent.goto(base + '/teacher/tutor/conversations/' + teacherId)).status(), 403);
        assert.equal((await teacher.goto(base + '/student/tutor/conversations/' + studentId)).status(), 403);
    });
    await check('QA007 no uncaught browser errors, HTTP 5xx or external provider requests', async () => {
        assert.deepEqual(errors, []);
        assert(!blockedExternal.some(origin => origin.includes('generativelanguage.googleapis.com')));
    });
    fs.writeFileSync(path.join(output, 'browser-tutor.json'), JSON.stringify({
        testedAt: new Date().toISOString(), browser: browser.version(),
        environment: `Synthetic loopback ${process.env.QA_BROWSER_DATABASE || 'SQLite'} on Windows; missing Gemini credentials; all external browser requests blocked; not a real device/provider test`,
        fixture: path.basename(process.env.QA_BROWSER_RUN || ''), results,
    }, null, 2));
    await browser.close();
    process.exitCode = results.some(result => result.result !== 'PASS') ? 1 : 0;
})().catch(async error => { console.error(error.message); if (browser) await browser.close(); process.exitCode = 1; });
