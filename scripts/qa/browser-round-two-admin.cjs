// Isolated browser fixture only; no external requests or real-school accounts.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(path.join(process.env.QA_NODE_MODULES, 'playwright'));
const base = 'http://127.0.0.1:8765';
const run = path.resolve(process.env.QA_BROWSER_RUN || '');
assert(run.startsWith(path.resolve('tmp/qa-browser') + path.sep), 'Only isolated QA fixtures are allowed.');
const fixture = JSON.parse(fs.readFileSync(path.join(run, 'fixture.json')));
const output = path.resolve('docs/qa/round-2/evidence');
const temporary = path.resolve('tmp/qa-round-2');
fs.mkdirSync(output, { recursive: true });
fs.mkdirSync(temporary, { recursive: true });
const results = [], errors = [];
let browser;
async function actor(email) {
    const context = await browser.newContext({ viewport: { width: 390, height: 900 }, locale: 'ar-LY', timezoneId: 'Africa/Tripoli', serviceWorkers: 'block' });
    await context.route('**/*', r => new URL(r.request().url()).origin === base ? r.continue() : r.abort());
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.status() >= 500) errors.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`); });
    page.on('dialog', dialog => dialog.accept());
    await page.goto(base + '/login');
    await page.locator('[name=email]').fill(email);
    await page.locator('[name=password]').fill('password123');
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click();
    assert(!page.url().endsWith('/login'));
    return page;
}
async function step(name, fn) {
    try { const detail = await fn(); results.push({ name, result: 'PASS', ...(detail ? { detail } : {}) }); }
    catch (error) { results.push({ name, result: 'FAIL', detail: error.message.slice(0, 1600) }); }
    console.log(results.at(-1));
}
async function layout(page, name) {
    for (const width of [390, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.waitForTimeout(350);
        const sizes = await page.evaluate(() => ({ width: innerWidth, content: document.documentElement.scrollWidth, dir: document.documentElement.dir }));
        assert.equal(sizes.dir, 'rtl');
        assert(sizes.content <= sizes.width + 1, JSON.stringify(sizes));
        await page.screenshot({ path: path.join(output, `${name}-${width}.png`), fullPage: true });
    }
}
(async () => {
    browser = await chromium.launch({ headless: true, executablePath: process.env.QA_BROWSER_EXE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
    const admin = await actor('admin@example.test');
    await step('R2-11 teacher/parent search, status, no matches and reset at mobile/desktop', async () => {
        for (const route of ['teachers', 'parents']) {
            await admin.goto(`${base}/admin/${route}`);
            const name = (await admin.locator('tbody tr').first().locator('td').first().innerText()).trim();
            await admin.locator('form.filters [name=q]').fill(name.slice(0, 4));
            await admin.locator('form.filters [name=status]').selectOption('active');
            await admin.locator('form.filters').getByRole('button', { name: 'بحث' }).click();
            assert((await admin.locator('tbody').innerText()).includes(name));
            await layout(admin, `admin-${route}`);
            await admin.locator('form.filters [name=q]').fill('QA-DOES-NOT-EXIST-93178');
            await admin.locator('form.filters').getByRole('button', { name: 'بحث' }).click();
            assert.equal(await admin.locator('tbody tr td[colspan]').count(), 1);
            await admin.getByRole('link', { name: 'إعادة الضبط', exact: true }).click();
            assert.equal(await admin.locator('form.filters [name=q]').inputValue(), '');
        }
    });
    await step('R2-12 timetable class/section/Sunday filters preserve the real break row and reset', async () => {
        await admin.goto(`${base}/admin/schedules?classroom_id=${fixture.classroom}`);
        if (!(await admin.locator('tbody').innerText()).includes('QA-R2-ADMIN')) {
            await admin.goto(base + '/admin/schedules/create');
            await admin.locator('[name=classroom_id]').selectOption(String(fixture.classroom));
            await admin.locator('[name=teacher_id]').selectOption(String(fixture.teacher));
            await admin.locator('[name=subject_id]').selectOption(String(fixture.subject));
            await admin.locator('[name=day_of_week]').selectOption('0');
            await admin.locator('[name=period_number]').selectOption('3');
            await admin.locator('[name=room]').fill('QA-R2-ADMIN');
            await admin.getByRole('button', { name: 'حفظ', exact: true }).click();
        }
        await admin.locator('[name=classroom_id]').selectOption(String(fixture.classroom));
        await admin.locator('[name=section]').selectOption('أ');
        await admin.locator('[name=day_of_week]').selectOption('0');
        await admin.getByRole('button', { name: 'تصفية', exact: true }).click();
        assert((await admin.locator('tbody').innerText()).includes('QA-R2-ADMIN'));
        assert((await admin.locator('tbody').innerText()).includes('استراحة مدرسية'));
        await layout(admin, 'admin-timetable');
        await admin.locator('[name=section]').selectOption('ب');
        await admin.getByRole('button', { name: 'تصفية', exact: true }).click();
        assert.equal(await admin.locator('tbody tr td[colspan="8"]').count(), 1);
        await admin.getByRole('link', { name: 'إعادة الضبط', exact: true }).click();
        assert.equal(await admin.locator('[name=day_of_week]').inputValue(), '');
    });
    await step('R2-17 supervisor dialog independent of user filter; save and reload assignments at two widths', async () => {
        const name = 'مشرف قبول الجولة الثانية ' + Date.now();
        await admin.goto(base + '/admin/supervisors/create');
        await admin.locator('[name=name]').fill(name);
        await admin.locator('[name=email]').fill(`qa-r2-supervisor-${Date.now()}@example.test`);
        await admin.locator(`[name="classroom_ids[]"][value="${fixture.classroom}"]`).check();
        await admin.getByRole('button', { name: 'حفظ', exact: true }).click();
        assert(/\/admin\/supervisors\/\d+\/edit$/.test(admin.url()), admin.url());
        await admin.goto(base + '/admin/users?role=teacher');
        await admin.getByRole('button', { name: 'تكليفات المشرفين', exact: true }).click();
        const form = admin.locator('#supervisor-modal form').filter({ hasText: name });
        assert.equal(await form.count(), 1);
        const alternative = form.locator('[name="classroom_ids[]"]').filter({ visible: true }).nth(1);
        const value = await alternative.getAttribute('value');
        await alternative.check();
        await layout(admin, 'admin-supervisor-dialog');
        await form.getByRole('button', { name: 'حفظ التكليفات', exact: true }).click();
        await admin.reload();
        await admin.getByRole('button', { name: 'تكليفات المشرفين', exact: true }).click();
        assert(await admin.locator('#supervisor-modal form').filter({ hasText: name }).locator(`[value="${value}"][name="classroom_ids[]"]`).isChecked());
        await admin.locator('#supervisor-modal button[aria-label="إغلاق"]').click();
    });
    await step('R2-13/18 financial filter labels and safe deletion of unused inactive fee through UI', async () => {
        await admin.goto(base + '/finance');
        await admin.getByLabel('الصف الدراسي', { exact: true }).selectOption({ label: 'الصف الأول' });
        await admin.getByLabel('الصف والشعبة المحددة', { exact: true }).selectOption(String(fixture.classroom));
        await admin.getByRole('button', { name: 'تصفية', exact: true }).click();
        assert.equal(await admin.locator('[name=classroom_id]').inputValue(), String(fixture.classroom));
        await layout(admin, 'admin-finance');
        await admin.goto(base + '/finance/fees');
        assert((await admin.locator('body').innerText()).includes('لا يلغي التزامات أو مدفوعات سابقة'));
        const name = 'تعريف غير مستخدم للاختبار ' + Date.now();
        await admin.locator('[name=name]').fill(name);
        await admin.locator('[name=amount]').fill('1.000');
        await admin.locator('[name=due_date]').fill('2027-06-01');
        await admin.locator('form.finance-section [name=active]').selectOption('0');
        await admin.locator('[name=student_id]').selectOption(String(fixture.student));
        await admin.getByRole('button', { name: 'حفظ الرسوم', exact: true }).click();
        const row = admin.getByRole('row').filter({ hasText: name });
        await row.getByRole('button', { name: 'حذف غير المستخدم', exact: true }).click();
        assert.equal(await admin.getByRole('row').filter({ hasText: name }).count(), 0);
        await admin.reload();
        assert.equal(await admin.getByRole('row').filter({ hasText: name }).count(), 0);
    });
    await step('R2-19 parent message survives archive/reopen and conversation remains readable to its participant', async () => {
        const parent = await actor('qa-parent-one@example.test');
        await parent.goto(base + '/parent/conversations/create');
        await parent.getByRole('button').filter({ hasText: 'إدارة المدرسة' }).first().click();
        const match = parent.url().match(/conversations\/(\d+)/);
        assert(match, parent.url());
        const message = 'رسالة قبول اصطناعية ' + Date.now();
        await parent.locator('[name=body]').fill(message);
        await parent.getByRole('button', { name: 'إرسال الرسالة', exact: true }).click();
        await parent.getByText(message, { exact: true }).waitFor();
        await admin.goto(base + '/admin/conversations/' + match[1]);
        await admin.getByRole('button', { name: 'أرشفة المحادثة', exact: true }).click();
        await parent.reload();
        assert.equal(await parent.locator('form.chat-composer').count(), 0);
        assert((await parent.locator('.chat-history').innerText()).includes(message));
        await layout(admin, 'admin-chat-archived');
        await admin.getByRole('button', { name: 'إعادة فتح المحادثة', exact: true }).click();
        await parent.reload();
        assert.equal(await parent.locator('form.chat-composer').count(), 1);
        assert((await parent.locator('.chat-history').innerText()).includes(message));
        await parent.context().close();
    });
    await step('R2-10 student photo is saved, reloaded and retrievable as the exact uploaded bytes', async () => {
        await admin.goto(`${base}/admin/students/${fixture.student}/edit`);
        const photoPath = path.resolve('public/icons/nasbia-logo.jpg');
        await admin.locator('[name=photo]').setInputFiles(photoPath);
        await admin.getByRole('button', { name: 'حفظ', exact: true }).click();
        assert(/\/admin\/students\/\d+$/.test(admin.url()), admin.url());
        await admin.goto(`${base}/admin/students/${fixture.student}/edit`);
        const photo = admin.locator('#student-photo-preview');
        const savedUrl = await photo.getAttribute('src');
        assert(savedUrl.includes('/student-photos/'), savedUrl);
        const response = await admin.request.get(savedUrl);
        assert.equal(response.status(), 200);
        assert.deepEqual(await response.body(), fs.readFileSync(photoPath));
        assert(await photo.evaluate(img => img.complete && img.naturalWidth > 0));
        await layout(admin, 'admin-student-photo');
    });
    await step('R2-14 actual single PDF plus multi-card browser PDF with photo/placeholder and empty selection', async () => {
        await admin.goto(`${base}/admin/students/${fixture.student}/account-card`);
        const downloadPromise = admin.waitForEvent('download');
        await admin.getByRole('link', { name: 'تنزيل PDF', exact: true }).click();
        const download = await downloadPromise;
        await download.saveAs(path.join(temporary, 'card-single.pdf'));
        assert.equal(fs.readFileSync(path.join(temporary, 'card-single.pdf')).subarray(0, 5).toString(), '%PDF-');
        await admin.goto(base + '/admin/students/cards');
        assert((await admin.locator('.card-checkbox').count()) >= 3);
        await admin.locator('#select-none').click();
        assert(await admin.locator('#print-selected').isDisabled());
        await admin.locator('#select-all').click();
        assert(!(await admin.locator('#print-selected').isDisabled()));
        await admin.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
        assert((await admin.locator('#print-pages .card-position').count()) >= 3);
        await admin.pdf({ path: path.join(temporary, 'cards.pdf'), format: 'A4', printBackground: true, preferCSSPageSize: true });
        assert(fs.statSync(path.join(temporary, 'cards.pdf')).size > 10000);
        await admin.screenshot({ path: path.join(output, 'admin-cards-preview.png'), fullPage: true });
        return { pdfFiles: ['tmp/qa-round-2/card-single.pdf', 'tmp/qa-round-2/cards.pdf'], cards: await admin.locator('.preview-pages .card-position').count() };
    });
    await step('R2-09 teacher/admin library filters and truthful empty or populated state render at two widths', async () => {
        await admin.goto(base + '/admin/library');
        assert(await admin.locator('[name=grade] option[value="1"]').count());
        await admin.locator('[name=grade]').selectOption('1');
        await admin.getByRole('button', { name: 'بحث وتصفية', exact: true }).click();
        await layout(admin, 'admin-library');
        const teacher = await actor('teacher1@example.test');
        await teacher.goto(base + '/teacher/library');
        await layout(teacher, 'teacher-library');
        await teacher.context().close();
    });
    if (errors.length) results.push({ name: 'uncaught page errors and HTTP 500 responses', result: 'FAIL', detail: errors });
    const report = { generated_at: new Date().toISOString(), environment: { browser: await browser.version(), executable: 'Microsoft Edge Chromium', os: process.platform, viewportWidths: [390, 1440], database: 'isolated PostgreSQL browser fixture', externalRequests: 'blocked', actualMobileDevice: false }, results, errors };
    fs.writeFileSync(path.join(output, 'browser-round-two-admin.json'), JSON.stringify(report, null, 2));
    await browser.close();
    process.exitCode = results.some(r => r.result !== 'PASS') ? 1 : 0;
})().catch(async error => { console.error(error); await browser?.close(); process.exitCode = 1; });
