// Synthetic loopback-only browser acceptance. Never points to the school site.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require(path.join(process.env.QA_NODE_MODULES, 'playwright'));
const base = 'http://127.0.0.1:8765';
const fixture = JSON.parse(fs.readFileSync(path.join(process.env.QA_BROWSER_RUN, 'fixture.json')));
const output = path.resolve('docs/qa/round-2/evidence');
fs.mkdirSync(output, { recursive: true });
const results = [], errors = [];
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=', 'base64');
let browser;
async function actor(email, width = 390) {
    const context = await browser.newContext({ viewport: { width, height: 900 }, locale: 'ar-LY', timezoneId: 'Africa/Tripoli', serviceWorkers: 'block' });
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${new URL(response.url()).pathname}`); });
    page.on('dialog', dialog => dialog.accept());
    await page.goto(base + '/login');
    await page.locator('[name=email]').fill(email);
    await page.locator('[name=password]').fill('password123');
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click();
    assert(!page.url().endsWith('/login'));
    return page;
}
async function check(name, fn) {
    try { const detail = await fn(); results.push({ name, result: 'PASS', ...(detail ? { detail } : {}) }); }
    catch (error) { results.push({ name, result: 'FAIL', detail: error.message.slice(0, 1400) }); }
    console.log(results.at(-1));
}
const visible = async (page, value) => page.getByText(value, { exact: false }).filter({ visible: true }).first().waitFor();
const announcementState = (id, action = 'inspect') => JSON.parse(execFileSync(process.env.QA_PHP_BINARY, ['scripts/qa/round-two-notify.php', id, action], { encoding: 'utf8', timeout: 30000 }));
async function screenshot(page, name) {
    await page.screenshot({ path: path.join(output, name), fullPage: true, animations: 'disabled' });
}
(async () => {
    browser = await chromium.launch({ headless: true, executablePath: process.env.QA_BROWSER_EXE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
    const teacher = await actor('teacher1@example.test');
    const student = await actor('student1@example.test');
    const admin = await actor('admin@example.test', 1440);
    const parent = await actor('parent@example.test');
    let lessonId;
    await check('I: interrupted create and lost committed response retain form, retry once without duplicate PNG/MP4', async () => {
        // Generate a small original MP4 using this browser, with no external media.
        const video = Buffer.from(await teacher.evaluate(async () => {
            const canvas = document.createElement('canvas'); canvas.width = 160; canvas.height = 90;
            const ctx = canvas.getContext('2d');
            const stream = canvas.captureStream(10), chunks = [];
            const recorder = new MediaRecorder(stream, { mimeType: 'video/mp4;codecs=avc1.42001E' });
            const done = new Promise(resolve => recorder.onstop = async () => resolve(Array.from(new Uint8Array(await new Blob(chunks).arrayBuffer()))));
            recorder.ondataavailable = event => chunks.push(event.data);
            recorder.start();
            for (let index = 0; index < 12; index++) { ctx.fillStyle = index % 2 ? '#2b6b75' : '#e5cb6b'; ctx.fillRect(0, 0, 160, 90); await new Promise(resolve => setTimeout(resolve, 100)); }
            recorder.stop(); stream.getTracks().forEach(track => track.stop()); return done;
        }));
        const title = 'درس إعادة المحاولة ' + Date.now();
        await teacher.goto(base + '/teacher/lessons/create');
        await teacher.locator('[name=subject_id]').selectOption(String(fixture.subject));
        await teacher.locator('[name=classroom_id]').selectOption(String(fixture.classroom));
        await teacher.locator('[name=title]').fill(title);
        await teacher.locator('[contenteditable]').fill('سطر عربي محفوظ بعد انقطاع الاتصال');
        await teacher.locator('[name="attachments[]"]').setInputFiles([
            { name: 'retry.png', mimeType: 'image/png', buffer: png },
            { name: 'retry.mp4', mimeType: 'video/mp4', buffer: video },
        ]);
        const key = await teacher.locator('[name=submission_key]').inputValue();
        let attempts = 0;
        await teacher.route(base + '/teacher/lessons', async route => {
            if (route.request().method() !== 'POST') return route.continue();
            attempts++;
            if (attempts === 1) return route.abort('internetdisconnected');
            if (attempts === 2) { const response = await route.fetch(); assert.equal(response.status(), 200); return route.abort('connectionreset'); }
            return route.continue();
        });
        const save = teacher.getByRole('button', { name: 'نشر الدرس', exact: true });
        for (let attempt = 0; attempt < 2; attempt++) {
            await save.click(); await visible(teacher, 'انقطع الاتصال');
            assert.equal(await teacher.locator('[name=title]').inputValue(), title);
            assert.equal(await teacher.locator('[name="attachments[]"]').evaluate(input => input.files.length), 2);
            assert.equal(await teacher.locator('[name=submission_key]').inputValue(), key);
            assert(await teacher.locator('[name=title]').isDisabled(), 'Ambiguous retry must not silently discard changed fields');
            assert(await teacher.getByRole('button', { name: 'حفظ كمسودة', exact: true }).isDisabled());
        }
        await screenshot(teacher, 'upload-retained-390.png');
        await save.click(); await teacher.waitForURL(base + '/teacher/lessons');
        const row = teacher.getByRole('row').filter({ hasText: title });
        assert.equal(await row.count(), 1);
        lessonId = (await row.getByRole('link', { name: 'تعديل', exact: true }).getAttribute('href')).match(/lessons\/(\d+)/)[1];
        await student.goto(base + '/student/subjects/' + fixture.subject + '/lessons/' + lessonId);
        await visible(student, 'سطر عربي محفوظ بعد انقطاع الاتصال');
        for (const [name, bytes] of [['retry.png', png], ['retry.mp4', video]]) {
            const link = student.getByRole('link').filter({ hasText: name }); assert.equal(await link.count(), 1);
            const downloadPromise = student.waitForEvent('download'); await link.click();
            assert.deepEqual(fs.readFileSync(await (await downloadPromise).path()), bytes);
        }
        const playback = await student.evaluate(async bytes => {
            const video = document.createElement('video'); video.muted = true;
            const url = URL.createObjectURL(new Blob([new Uint8Array(bytes)], { type: 'video/mp4' }));
            video.src = url; document.body.append(video);
            try { await video.play(); await new Promise(resolve => setTimeout(resolve, 350)); return { time: video.currentTime, width: video.videoWidth }; }
            finally { video.pause(); video.remove(); URL.revokeObjectURL(url); }
        }, [...video]);
        assert(playback.time > 0 && playback.width === 160, JSON.stringify(playback));
        await screenshot(student, 'lesson-attachments-390.png');
        return 'Three real requests: pre-server disconnect, post-commit lost response, replay. Full-file retry. Downloaded original MP4 decoded/played in desktop Edge; no physical phone/camera test.';
    });
    await check('I: edit retry after lost committed response adds attachment only once', async () => {
        assert(lessonId);
        await teacher.goto(base + '/teacher/lessons/' + lessonId + '/edit');
        await teacher.locator('[name="attachments[]"]').setInputFiles({ name: 'edit-retry.png', mimeType: 'image/png', buffer: png });
        let calls = 0;
        await teacher.route(base + '/teacher/lessons/' + lessonId, async route => {
            if (route.request().method() !== 'POST') return route.continue();
            if (++calls === 1) { const response = await route.fetch(); assert.equal(response.status(), 200); return route.abort('connectionreset'); }
            return route.continue();
        });
        await teacher.getByRole('button', { name: 'تعديل', exact: true }).click(); await visible(teacher, 'انقطع الاتصال');
        await teacher.getByRole('button', { name: 'تعديل', exact: true }).click(); await teacher.waitForURL(base + '/teacher/lessons');
        await student.reload(); assert.equal(await student.getByRole('link').filter({ hasText: 'edit-retry.png' }).count(), 1);
        const outside = await actor('qa-outsider@example.test');
        const attachment = await student.getByRole('link').filter({ hasText: 'retry.mp4' }).getAttribute('href');
        assert.equal((await outside.request.get(attachment)).status(), 404); await outside.context().close();
    });
    await check('I: validation permits corrections; expired-session retry refreshes CSRF without losing selected files', async () => {
        await teacher.unroute(base + '/teacher/lessons');
        await teacher.goto(base + '/teacher/lessons/create');
        await teacher.locator('[name=subject_id]').selectOption(String(fixture.subject));
        await teacher.locator('[name=classroom_id]').selectOption(String(fixture.classroom));
        await teacher.locator('[name=title]').fill('اختبار تصحيح الطلب');
        await teacher.locator('[name="attachments[]"]').setInputFiles({ name: 'csrf.png', mimeType: 'image/png', buffer: png });
        let calls = 0, refreshed = 0;
        teacher.on('request', request => { if (request.method() === 'GET' && request.url() === base + '/teacher/lessons/create') refreshed++; });
        await teacher.route(base + '/teacher/lessons', route => {
            if (route.request().method() !== 'POST') return route.continue();
            calls++;
            if (calls === 1) return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ errors: { title: ['خطأ تحقق اصطناعي'] } }) });
            if (calls === 2) return route.fulfill({ status: 419, contentType: 'application/json', body: '{}' });
            return route.continue();
        });
        const save = teacher.getByRole('button', { name: 'نشر الدرس', exact: true });
        await save.click(); await visible(teacher, 'خطأ تحقق اصطناعي');
        assert(await teacher.locator('[name=title]').isEnabled());
        const title = 'طلب مصحح ' + Date.now(); await teacher.locator('[name=title]').fill(title);
        await save.click(); await visible(teacher, 'انتهت الجلسة');
        await save.click(); await teacher.waitForURL(base + '/teacher/lessons');
        assert.equal(refreshed, 1); assert.equal(await teacher.getByRole('row').filter({ hasText: title }).count(), 1);
        return '422/419 responses injected at browser transport; authenticated fresh-form CSRF retrieval and final save are real loopback requests.';
    });
    await check('E: immediate and school-time scheduled announcements, pending visibility, scheduler dedup and archive', async () => {
        const title = 'إعلان توقيت الجولة الثانية ' + Date.now();
        let scheduledId, due, localDate;
        for (const suffix of ['فوري', 'مجدول']) {
            await admin.goto(base + '/admin/announcements/create');
            await admin.locator('[name=title]').fill(title + ' ' + suffix);
            await admin.locator('[name=content]').fill('إعلان اصطناعي للتحقق من التوقيت');
            await admin.locator('[name=audience]').selectOption('parents');
            await admin.locator('[name=classroom_id]').selectOption(String(fixture.classroom));
            await admin.locator('[name=status]').selectOption('published');
            // Choose the minute boundary only after this form is ready. Keep at
            // least a minute for the real before-due browser and DB observations.
            if (suffix === 'مجدول') {
                due = new Date(Math.ceil((Date.now() + 60000) / 60000) * 60000);
                localDate = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Africa/Tripoli', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(due).replace(' ', 'T');
            }
            await admin.locator('[name=published_at]').fill(suffix === 'مجدول' ? localDate : '');
            assert.equal(await admin.locator('[name=schedule_timezone]').inputValue(), 'Africa/Tripoli');
            await admin.getByRole('button', { name: 'حفظ', exact: true }).click();
            const row = admin.getByRole('row').filter({ hasText: title + ' ' + suffix });
            await row.waitFor();
            if (suffix === 'مجدول') scheduledId = (await row.locator('form[action$="/archive"]').getAttribute('action')).match(/announcements\/(\d+)/)[1];
        }
        await parent.goto(base + '/parent/ads'); await visible(parent, title + ' فوري');
        const beforeDue = announcementState(scheduledId);
        assert.equal(Date.parse(beforeDue.publishedUTC), due.getTime(), 'School-local input must store the intended UTC instant: ' + JSON.stringify(beforeDue));
        assert(Date.parse(beforeDue.serverUTC) < due.getTime(), 'Test setup crossed its future boundary before the hidden-state check: ' + JSON.stringify(beforeDue));
        assert.equal(beforeDue.currentlyPublished, false, 'Future announcement must not be publishable: ' + JSON.stringify(beforeDue));
        assert.equal(beforeDue.before, 0, 'No internal notification may exist before publication: ' + JSON.stringify(beforeDue));
        assert.equal(await parent.getByText(title + ' مجدول', { exact: true }).count(), 0, 'Scheduled title must be absent before its due time: ' + JSON.stringify(beforeDue));
        console.log({ announcementPending: beforeDue });
        while (Date.now() < due.getTime() + 1000) await new Promise(resolve => setTimeout(resolve, Math.min(1000, due.getTime() + 1000 - Date.now())));
        // Parent summary intentionally recovers due announcements. A live bell
        // poll may win the race with the scheduler; explicitly exercise that
        // supported order, then prove the scheduler completes without duplicates.
        const summary = await parent.request.get(base + '/parent/notifications/summary');
        assert.equal(summary.status(), 200, 'Due parent notification summary must succeed');
        const afterParentSummary = announcementState(scheduledId);
        assert(afterParentSummary.before > 0, 'Parent summary must recover the due announcement before the scheduler: ' + JSON.stringify(afterParentSummary));
        const delivery = announcementState(scheduledId, 'notify');
        assert(delivery.currentlyPublished && Date.parse(delivery.serverUTC) >= due.getTime(), 'Scheduler must execute after the actual boundary: ' + JSON.stringify(delivery));
        assert.equal(delivery.before, afterParentSummary.before, 'Scheduler pre-count must preserve recovered rows: ' + JSON.stringify(delivery));
        assert(delivery.first >= delivery.before && delivery.first > 0, 'Scheduler must preserve and complete notifications: ' + JSON.stringify(delivery));
        assert.equal(delivery.first, delivery.repeated, 'Repeated scheduler must not duplicate notifications: ' + JSON.stringify(delivery));
        await parent.reload(); await visible(parent, title + ' مجدول');
        await admin.goto(base + '/admin/announcements?search=' + encodeURIComponent(title) + '&audience=parents&status=published&classroom_id=' + fixture.classroom);
        assert.equal(await admin.locator('tbody tr').count(), 2);
        await screenshot(admin, 'announcement-filters-1440.png');
        await Promise.all([
            admin.waitForResponse(response => response.url().endsWith('/announcements/' + scheduledId + '/archive') && response.request().method() === 'POST' && response.status() === 302),
            admin.getByRole('row').filter({ hasText: title + ' مجدول' }).locator('form[action$="/archive"] button').click(),
        ]);
        const archived = announcementState(scheduledId);
        assert.equal(archived.status, 'archived', 'Archive response must commit before recipient refresh: ' + JSON.stringify(archived));
        await parent.reload(); assert.equal(await parent.getByText(title + ' مجدول', { exact: true }).count(), 0, 'Archived announcement must be absent for the parent');
        return { created: new Date().toISOString(), scheduledInput: localDate, visibleAfterUTC: due.toISOString(), beforeDue, afterParentSummary, notificationCounts: delivery, archived };
    });
    await check('E: eligible announcement draft deletion and explicit unavailable phone-push state', async () => {
        const title = 'مسودة قابلة للحذف ' + Date.now();
        await admin.goto(base + '/admin/announcements/create');
        await admin.locator('[name=title]').fill(title); await admin.locator('[name=content]').fill('مسودة اصطناعية');
        await admin.locator('[name=audience]').selectOption('parents'); await admin.locator('[name=status]').selectOption('draft');
        await admin.getByRole('button', { name: 'حفظ', exact: true }).click();
        await admin.getByRole('row').filter({ hasText: title }).locator('form').filter({ has: admin.locator('input[name=_method][value="delete" i]') }).locator('button').click();
        assert.equal(await admin.getByRole('row').filter({ hasText: title }).count(), 0);
        await parent.goto(base + '/parent/dashboard'); await parent.locator('[data-fcm-message]').waitFor();
        assert.match(await parent.locator('[data-fcm-message]').innerText(), /غير|متعذر|غير متاح|دعم|مهيأ|جاهز/);
    });
    await check('Browser runtime contains no uncaught errors or HTTP 5xx', async () => assert.deepEqual(errors, []));
    fs.writeFileSync(path.join(output, 'browser-round-two.json'), JSON.stringify({ testedAt: new Date().toISOString(), browser: browser.version(), os: process.platform, viewports: ['390x900', '1440x900'], environment: 'Loopback isolated PostgreSQL, synthetic identities, external requests blocked', results }, null, 2));
    await browser.close(); process.exitCode = results.some(result => result.result !== 'PASS') ? 1 : 0;
})().catch(async error => { console.error(error.message); if (browser) await browser.close(); process.exitCode = 1; });
