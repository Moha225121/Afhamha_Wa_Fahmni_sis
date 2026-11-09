const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(path.join(process.env.QA_NODE_MODULES, 'playwright'));
const base = 'http://127.0.0.1:8765';
const fixture = JSON.parse(fs.readFileSync(path.join(process.env.QA_BROWSER_RUN, 'fixture.json')));
const output = path.resolve('docs/qa/evidence');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=', 'base64');
const results = [];
const errors = [];
let browser;
async function actor(email) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 900 }, locale: 'ar-LY', timezoneId: 'Africa/Tripoli', serviceWorkers: 'block' });
    await ctx.route('**/*', r => new URL(r.request().url()).hostname === '127.0.0.1' ? r.continue() : r.abort());
    const p = await ctx.newPage(); p.setDefaultTimeout(12000);
    p.on('pageerror', e => errors.push(e.message));
    p.on('response', response => {
        if (response.status() >= 500) errors.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`);
    });
    await p.goto(base + '/login'); await p.locator('[name=email]').fill(email); await p.locator('[name=password]').fill('password123');
    await p.getByRole('button', { name: 'تسجيل الدخول' }).click();
    assert(!p.url().endsWith('/login')); return p;
}
async function check(name, fn) {
    try { await fn(); results.push({ name, result: 'PASS' }); }
    catch (e) { results.push({ name, result: 'FAIL', detail: e.message.slice(0, 1000) }); }
    console.log(results.at(-1));
}
async function textVisible(p, text) { await p.getByText(text, { exact: false }).filter({ visible: true }).first().waitFor(); }
(async () => {
    browser = await chromium.launch({ headless: true, executablePath: process.env.QA_BROWSER_EXE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
    const teacher = await actor('teacher1@example.test'), student = await actor('student1@example.test');
    const parent = await actor('parent@example.test'), admin = await actor('admin@example.test');
    await check('Journey2 teacher attachment -> student upload -> draft hidden -> grade published to student and parent', async () => {
        const title = 'واجب رحلة متصفح ' + Date.now();
        await teacher.goto(base + '/teacher/assignments/create');
        await teacher.locator('[name=title]').fill(title);
        await teacher.locator('[name=subject_id]').selectOption(String(fixture.subject));
        await teacher.locator('[name=classroom_id]').selectOption(String(fixture.classroom));
        await teacher.locator('[name=max_score]').fill('20');
        await teacher.locator('[name=due_date]').fill(new Date(Date.now() + 86400000).toISOString().slice(0, 10));
        await teacher.locator('[contenteditable]').fill('حل اصطناعي للتحقق من المسار المتكامل');
        await teacher.locator('[name="attachments[]"]').setInputFiles({ name: 'qa-task.png', mimeType: 'image/png', buffer: png });
        await teacher.getByRole('button', { name: 'إنشاء الواجب', exact: true }).click();
        const row = teacher.getByRole('row').filter({ hasText: title });
        const submissionsUrl = await row.getByRole('link', { name: 'التسليمات' }).getAttribute('href');
        const id = submissionsUrl.match(/assignments\/(\d+)/)[1];
        await student.goto(base + '/student/assignments/' + id);
        const download1 = student.waitForEvent('download'); await student.getByRole('link', { name: 'qa-task.png' }).click();
        assert.deepEqual(fs.readFileSync(await (await download1).path()), png);
        await student.locator('[name=file]').setInputFiles({ name: 'qa-answer.png', mimeType: 'image/png', buffer: png });
        await student.locator('[name=notes]').fill('تسليم اصطناعي من المتصفح');
        await student.getByRole('button', { name: 'تسليم الواجب', exact: true }).click();
        await student.reload(); await textVisible(student, 'qa-answer.png');
        await teacher.goto(submissionsUrl);
        const download2 = teacher.waitForEvent('download'); await teacher.getByRole('link', { name: 'تنزيل الملف' }).click();
        assert.deepEqual(fs.readFileSync(await (await download2).path()), png);
        await teacher.locator(`[name="scores[${fixture.student}]"]`).fill('17');
        await teacher.getByRole('button', { name: 'حفظ كمسودة' }).click();
        await student.reload(); assert.equal(await student.getByRole('heading', { name: 'نتيجة الواجب المنشورة', exact: true }).count(), 0);
        await teacher.getByRole('button', { name: 'حفظ ونشر الدرجات' }).click();
        await student.reload(); await textVisible(student, '17 / 20');
        await parent.goto(base + '/parent/assignments?student=' + fixture.student);
        await textVisible(parent.locator('article').filter({ hasText: title }), '17 / 20');
        await student.screenshot({ path: path.join(output, 'assignment-published-student.png'), fullPage: true, animations: 'disabled' });
    });
    await check('Journey4 parent -> teacher and admin conversations; reply, order and Tripoli timestamps', async () => {
        for (const [recipient, p] of [['معلم الصف', teacher], ['إدارة المدرسة', admin]]) {
            await parent.goto(base + '/parent/conversations/create?student=' + fixture.student);
            await parent.locator('button.chat-recipient').filter({ hasText: recipient }).click();
            const id = parent.url().match(/conversations\/(\d+)/)[1];
            const body = 'رسالة اصطناعية ' + recipient + ' ' + Date.now();
            await parent.locator('[name=body]').fill(body);
            await parent.getByRole('button', { name: 'إرسال الرسالة', exact: true }).click();
            await textVisible(parent, body);
            await p.goto(base + '/' + (p === teacher ? 'teacher' : 'admin') + '/conversations/' + id);
            await textVisible(p, body);
            await p.locator('[name=body]').fill('رد اصطناعي ' + body);
            await p.getByRole('button', { name: 'إرسال الرسالة', exact: true }).click();
            await textVisible(p, 'رد اصطناعي ' + body);
            await parent.reload(); await textVisible(parent, 'رد اصطناعي ' + body);
            const time = parent.locator('article.chat-bubble time').last();
            const expected = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Tripoli' }).format(new Date(await time.getAttribute('datetime')));
            assert.equal(await time.innerText(), expected);
        }
        await parent.screenshot({ path: path.join(output, 'parent-chat-tripoli.png'), fullPage: true, animations: 'disabled' });
    });
    await check('Journey5 admin announcement published for second-child class; one-child parent excluded, drafts hidden', async () => {
        const title = 'إعلان متصفح ' + Date.now();
        for (const state of ['draft', 'published']) {
            await admin.goto(base + '/admin/announcements/create');
            await admin.locator('[name=title]').fill(title + '-' + state);
            await admin.locator('[name=content]').fill('محتوى إعلان اصطناعي محدد الجمهور');
            await admin.locator('[name=audience]').selectOption('parents');
            await admin.locator('[name=classroom_id]').selectOption('2');
            await admin.locator('[name=published_at]').fill(new Date(Date.now() - 3600000).toISOString().slice(0, 16));
            await admin.locator('[name=status]').selectOption(state);
            await admin.getByRole('button', { name: 'حفظ', exact: true }).click();
        }
        await parent.goto(base + '/parent/ads');
        await textVisible(parent, title + '-published');
        assert.equal(await parent.getByText(title + '-draft', { exact: true }).count(), 0);
        const one = await actor('qa-parent-one@example.test'); await one.goto(base + '/parent/ads');
        assert.equal(await one.getByText(title + '-published', { exact: true }).count(), 0);
        await one.context().close();
    });
    await check('Journey6 supervisor visible note reaches linked guardian', async () => {
        const supervisor = await actor('supervisor@example.test');
        const title = 'متابعة متصفح ' + Date.now();
        await supervisor.goto(base + '/supervisor/student-notes');
        await supervisor.locator('[name=student_id]').selectOption(String(fixture.student));
        await supervisor.locator('[name=title]').fill(title);
        await supervisor.locator('[name=content]').fill('ملاحظة متابعة اصطناعية');
        await supervisor.locator('[name=visibility]').selectOption('guardian');
        await supervisor.getByRole('button', { name: 'حفظ الملاحظة' }).click();
        await parent.goto(base + '/parent/notifications'); await textVisible(parent, title);
        await supervisor.context().close();
    });
    await check('QA007 existing student tutor new conversation remains private', async () => {
        await student.goto(base + '/student/tutor');
        await student.getByRole('button', { name: 'محادثة جديدة', exact: true }).click();
        assert(/\/student\/tutor\/conversations\/\d+$/.test(student.url()));
        await student.reload(); await textVisible(student, 'ابدأ بالسؤال');
    });
    await check('No uncaught browser errors or HTTP 5xx during learning journeys', async () => assert.deepEqual(errors, []));
    fs.writeFileSync(path.join(output, 'browser-learning.json'), JSON.stringify({ testedAt: new Date().toISOString(), browser: browser.version(), environment: 'Synthetic loopback browser; no paid/external calls or physical device', results }, null, 2));
    await browser.close(); process.exitCode = results.some(r => r.result !== 'PASS') ? 1 : 0;
})().catch(async e => { console.error(e.message); if (browser) await browser.close(); process.exitCode = 1; });
