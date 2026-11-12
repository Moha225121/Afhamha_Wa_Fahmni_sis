const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(path.join(process.env.QA_NODE_MODULES, 'playwright'));
const base = 'http://127.0.0.1:8765';
const fixture = JSON.parse(fs.readFileSync(path.join(process.env.QA_BROWSER_RUN, 'fixture.json')));
const output = path.resolve(process.env.QA_EVIDENCE_DIR || 'docs/qa/evidence');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=', 'base64');
const results = [], errors = [];
let browser;
const visible = async (p, value) => p.getByText(value, { exact: false }).filter({ visible: true }).first().waitFor();
async function actor(email) {
    const context = await browser.newContext({ viewport: { width: 390, height: 900 }, locale: 'ar-LY', timezoneId: 'Africa/Tripoli', serviceWorkers: 'block' });
    await context.route('**/*', r => new URL(r.request().url()).origin === base ? r.continue() : r.abort());
    const page = await context.newPage(); page.setDefaultTimeout(12000);
    page.on('pageerror', e => errors.push(e.message));
    page.on('response', response => {
        if (response.status() >= 500) errors.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`);
    });
    page.on('dialog', dialog => dialog.accept());
    await page.goto(base + '/login');
    await page.locator('[name=email]').fill(email);
    await page.locator('[name=password]').fill('password123');
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click();
    assert(!page.url().endsWith('/login')); return page;
}
async function step(name, fn) {
    try { const detail = await fn(); results.push({ name, result: 'PASS', ...(detail ? { detail } : {}) }); }
    catch (e) { results.push({ name, result: 'FAIL', detail: e.message.slice(0, 1300) }); throw e; }
    finally { console.log(results.at(-1)); }
}
async function shot(page, name) {
    // Allow the existing portal entrance animation to finish before capturing evidence.
    await page.waitForTimeout(1100);
    await page.screenshot({ path: path.join(output, name), fullPage: true });
}
(async () => {
    fs.mkdirSync(output, { recursive: true });
    browser = await chromium.launch({ headless: true, executablePath: process.env.QA_BROWSER_EXE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
    const teacher = await actor('teacher1@example.test'), student = await actor('student1@example.test');
    const outsider = await actor('qa-outsider@example.test'), admin = await actor('admin@example.test'), parent = await actor('parent@example.test');
    let lessonUrl, downloadUrl;
    try {
        const title = 'درس رحلة أكاديمية ' + Date.now();
        await step('Journey1 teacher creates and publishes lesson with PNG through UI', async () => {
            await teacher.goto(base + '/teacher/lessons/create');
            await teacher.locator('[name=subject_id]').selectOption(String(fixture.subject));
            await teacher.locator('[name=classroom_id]').selectOption(String(fixture.classroom));
            await teacher.locator('[name=title]').fill(title);
            await teacher.locator('[name=unit_title]').fill('وحدة رحلة متصفح اصطناعية');
            await teacher.locator('[contenteditable]').fill('محتوى درس اصطناعي منشور من واجهة المعلم');
            await teacher.locator('[name="attachments[]"]').setInputFiles({ name: 'qa-lesson-journey.png', mimeType: 'image/png', buffer: png });
            await teacher.getByRole('button', { name: 'نشر الدرس', exact: true }).click();
            const row = teacher.getByRole('row').filter({ hasText: title });
            const editUrl = await row.getByRole('link', { name: 'تعديل', exact: true }).getAttribute('href');
            lessonUrl = base + '/student/subjects/' + fixture.subject + '/lessons/' + editUrl.match(/lessons\/(\d+)/)[1];
            await shot(teacher, 'lesson-created-teacher.png');
        });
        await step('Journey1 authorized student opens new lesson and downloads exact PNG bytes', async () => {
            await student.goto(lessonUrl); await visible(student, 'محتوى درس اصطناعي منشور من واجهة المعلم');
            const link = student.getByRole('link').filter({ hasText: 'qa-lesson-journey.png' });
            downloadUrl = await link.getAttribute('href');
            const download = student.waitForEvent('download'); await link.click();
            assert.deepEqual(fs.readFileSync(await (await download).path()), png);
            await shot(student, 'lesson-journey-student.png');
        });
        await step('Journey1 outsider lesson and attachment browser navigations both return 404', async () => {
            assert.equal((await outsider.goto(lessonUrl)).status(), 404);
            assert.equal((await outsider.goto(downloadUrl)).status(), 404);
        });
    } catch (e) { await shot(teacher, 'academics-lesson-failure.png'); }
    let examId, reviewUrl;
    const examTitle = 'اختبار رحلة أكاديمية ' + Date.now(), periodTitle = 'نتيجة رحلة أكاديمية ' + Date.now();
    try {
        await step('Journey3 teacher creates scheduled objective and manual exam through UI', async () => {
            await teacher.goto(base + '/teacher/exams/create');
            await teacher.locator('[name=title]').fill(examTitle);
            await teacher.locator('[name=subject_id]').selectOption(String(fixture.subject));
            await teacher.locator('[name=classroom_id]').selectOption(String(fixture.classroom));
            await teacher.locator('[name=starts_at]').fill(new Date(Date.now() + 600000).toISOString().slice(0, 16));
            await teacher.locator('[name=duration_minutes]').fill('30');
            await teacher.locator('.question-card').first().locator('[contenteditable]').fill('2 + 2 = ?');
            await teacher.locator('[name="questions[0][score]"]').fill('10');
            await teacher.locator('[name="questions[0][choices][0][text]"]').fill('4');
            await teacher.locator('[name="questions[0][choices][1][text]"]').fill('3');
            await teacher.getByRole('button', { name: 'إضافة سؤال جديد' }).click();
            await teacher.locator('[name="questions[1][type]"]').selectOption('short_answer');
            await teacher.locator('[name="questions[1][score]"]').fill('10');
            await teacher.locator('.question-card').nth(1).locator('[contenteditable]').fill('اشرح إجابتك اصطناعيًا');
            const richEditor = teacher.locator('.question-card').nth(1).locator('[contenteditable]');
            await richEditor.press('End');
            await richEditor.press('Shift+Enter');
            await richEditor.pressSequentially('2 < 5 & 7 > 3');
            await richEditor.press('Enter');
            await richEditor.pressSequentially('سطر عربي جديد');
            await teacher.getByRole('button', { name: 'إضافة سؤال جديد' }).click();
            await teacher.locator('[name="questions[2][type]"]').selectOption('true_false');
            await teacher.locator('[name="questions[2][score]"]').fill('10');
            await teacher.locator('.question-card').nth(2).locator('[contenteditable]').fill('العبارة الاصطناعية خاطئة');
            await teacher.locator('[name="questions[2][choices][1][is_correct]"]').check();
            await teacher.getByRole('button', { name: 'نشر الاختبار', exact: true }).click();
            const row = teacher.getByRole('row').filter({ hasText: examTitle });
            reviewUrl = await row.getByRole('link', { name: 'المحاولات والتصحيح' }).getAttribute('href');
            examId = reviewUrl.match(/exams\/(\d+)/)[1];
        });
        await step('Journey3 before start exam is upcoming with no Start control', async () => {
            await student.goto(base + '/student/exams');
            const row = student.locator('.list-row').filter({ hasText: examTitle });
            await visible(row, 'قادم');
            assert.equal(await row.getByRole('button', { name: 'ابدأ', exact: true }).count(), 0);
            await shot(student, 'exam-upcoming-student.png');
            return 'UI restriction only; server-side premature POST rejection is PHPUnit evidence, not asserted as browser interaction.';
        });
        await step('Journey3 teacher advances schedule through edit UI and student completes both question types', async () => {
            await teacher.goto(base + '/teacher/exams/' + examId + '/edit');
            assert.equal(await teacher.locator('[name="questions[2][choices][1][is_correct]"]').isChecked(), true, 'Reloading a saved False answer must not silently change its answer key to True');
            assert.equal(await teacher.locator('[name="questions[2][choices][0][is_correct]"]').isChecked(), false);
            await visible(teacher.locator('.rich-editor').nth(1), 'سطر عربي جديد');
            assert((await teacher.locator('.rich-editor').nth(1).innerText()).includes('2 < 5 & 7 > 3'));
            await teacher.locator('[name=starts_at]').fill(new Date(Date.now() - 60000).toISOString().slice(0, 16));
            await teacher.getByRole('button', { name: 'تحديث الاختبار', exact: true }).click();
            await student.goto(base + '/student/exams');
            await student.locator('.list-row').filter({ hasText: examTitle }).getByRole('button', { name: 'ابدأ', exact: true }).click();
            const questionContent = student.locator('.question-form').nth(1).locator('.assignment-content');
            await visible(questionContent, 'سطر عربي جديد');
            const renderedQuestion = await questionContent.innerText();
            assert(renderedQuestion.includes('2 < 5 & 7 > 3'));
            assert(!/(?:<\/?div|<br|&nbsp;|&lt;|&gt;)/i.test(renderedQuestion), renderedQuestion);
            await shot(student, 'exam-question-formatted-student.png');
            const objective = student.locator('form.question-form').first();
            await objective.getByRole('radio', { name: '4', exact: true }).check();
            await objective.getByRole('button', { name: 'حفظ الإجابة' }).click();
            const manual = student.locator('form.question-form').nth(1);
            await manual.locator('[name=answer]').fill('إجابة تفسيرية اصطناعية من المتصفح');
            await manual.getByRole('button', { name: 'حفظ الإجابة' }).click();
            const trueFalse = student.locator('form.question-form').nth(2);
            await trueFalse.getByRole('radio', { name: 'خطأ', exact: true }).check();
            await trueFalse.getByRole('button', { name: 'حفظ الإجابة' }).click();
            await student.getByRole('button', { name: 'إرسال الاختبار', exact: true }).click();
            await shot(student, 'exam-submitted-student.png');
        });
        await step('Journey3 teacher reviews manual answer and preserves automatic score', async () => {
            await teacher.goto(reviewUrl); await visible(teacher, 'إجابة تفسيرية اصطناعية من المتصفح');
            const reviewContent = teacher.locator('.assignment-content').nth(1);
            await visible(reviewContent, 'سطر عربي جديد');
            const reviewedQuestion = await reviewContent.innerText();
            assert(reviewedQuestion.includes('2 < 5 & 7 > 3'));
            assert(!/(?:<\/?div|<br|&nbsp;|&lt;|&gt;)/i.test(reviewedQuestion), reviewedQuestion);
            await visible(teacher, 'التصحيح الآلي: 10');
            await teacher.locator('input[name^="scores["]').fill('7');
            await teacher.getByRole('button', { name: 'حفظ التصحيح' }).click();
            await visible(teacher, '27.00 / 30.00');
            await shot(teacher, 'exam-reviewed-teacher.png');
        });
        await step('Journey3 admin imports into draft, reviews, approves and publishes; student and parent see 90 percent', async () => {
            await admin.goto(base + '/admin/results');
            const form = admin.locator('form').filter({ has: admin.locator('[name=name]') });
            await form.locator('[name=name]').fill(periodTitle);
            await form.locator('[name=classroom_id]').selectOption(String(fixture.classroom));
            await form.getByRole('button', { name: 'إنشاء مسودة' }).click();
            if (!/\/admin\/results\/\d+$/.test(admin.url())) await admin.getByRole('link', { name: periodTitle, exact: true }).click();
            await admin.getByText('استيراد درجات الاختبارات', { exact: true }).click();
            const importForm = admin.locator('form').filter({ has: admin.locator('[name="exam_ids[]"]') });
            await importForm.locator('[name="exam_ids[]"][value="' + examId + '"]').check();
            await importForm.getByRole('button', { name: 'استيراد إلى المسودة', exact: true }).click();
            await student.goto(base + '/student/results'); assert.equal(await student.getByText(periodTitle, { exact: true }).count(), 0);
            await parent.goto(base + '/parent/results?student=' + fixture.student); assert.equal(await parent.getByText(periodTitle, { exact: true }).count(), 0);
            for (const state of ['under_review', 'approved', 'published']) {
                await admin.locator('[name=status]').selectOption(state);
                await admin.getByRole('button', { name: 'اعتماد تغيير الحالة' }).click();
            }
            await student.reload(); await visible(student, periodTitle); await visible(student, '90%');
            await parent.reload(); await visible(parent, periodTitle); await visible(parent, '90%');
            await shot(student, 'exam-result-published-student.png');
            await shot(parent, 'exam-result-published-parent.png');
        });
    } catch (e) { await shot(teacher, 'academics-exam-teacher-failure.png'); await shot(student, 'academics-exam-student-failure.png'); await shot(admin, 'academics-exam-admin-failure.png'); }
    await step('No uncaught browser errors or HTTP 5xx during academic journeys', async () => assert.deepEqual(errors, []));
    fs.writeFileSync(path.join(output, 'browser-academics.json'), JSON.stringify({ testedAt: new Date().toISOString(), browser: browser.version(), viewport: '390x900', environment: 'Synthetic loopback fixture; browser UI and downloads only; external requests and service workers blocked', results }, null, 2));
    await browser.close(); process.exitCode = results.some(r => r.result !== 'PASS') ? 1 : 0;
})().catch(async e => { console.error(e.message); fs.writeFileSync(path.join(output, 'browser-academics.json'), JSON.stringify({ results, fatal: e.message }, null, 2)); if (browser) await browser.close(); process.exitCode = 1; });
