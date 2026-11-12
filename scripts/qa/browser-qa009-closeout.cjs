const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require(path.join(process.env.QA_NODE_MODULES, 'playwright'));
const base = process.env.QA_BASE_URL || 'http://127.0.0.1:8765';
assert(['http://127.0.0.1:8765', 'http://127.0.0.1:8766'].includes(base));
assert(process.env.QA_PHP_BINARY, 'Resolve PHP_BINARY before running this isolated browser check.');
const output = path.resolve(process.env.QA_EVIDENCE_DIR || 'docs/qa/evidence');
const results = [], errors = [];
const startedAt = new Date().toISOString();
let browser;
function snapshot(name) {
    return JSON.parse(execFileSync(process.env.QA_PHP_BINARY, ['scripts/qa/browser-class-snapshot.php', name], { encoding: 'utf8', env: process.env }));
}
async function check(name, fn) {
    try { results.push({ name, result: 'PASS', ...(await fn()) }); }
    catch (error) { results.push({ name, result: 'FAIL', detail: error.message.slice(0, 1400) }); }
    console.log(JSON.stringify(results.at(-1)));
}
async function fill(page, values, subjects) {
    await page.locator('[name=name]').fill(values.name);
    await page.locator('[name=stage]').fill(values.stage);
    await page.locator('[name=section]').fill(values.section);
    await page.locator('[name=academic_year_id]').selectOption(values.academic_year_id);
    for (const box of await page.locator('[name="subject_ids[]"]').all()) {
        const selected = subjects.includes(await box.inputValue());
        if (await box.isChecked() !== selected) await box.setChecked(selected);
    }
}
async function submit(page) {
    const responsePromise = page.waitForResponse(r => new URL(r.url()).pathname.startsWith('/admin/classes') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'حفظ', exact: true }).click();
    const response = await responsePromise;
    await page.waitForLoadState('domcontentloaded');
    return { status: response.status(), location: response.headers().location, method: response.request().method() };
}
async function validatedPost(page, values) {
    const token = await page.locator('input[name=_token]').first().inputValue();
    const params = new URLSearchParams({ _token: token });
    for (const [key, value] of Object.entries(values)) {
        if (Array.isArray(value)) value.forEach(item => params.append(key + '[]', item));
        else params.set(key, value);
    }
    const response = await page.request.post(base + '/admin/classes', {
        data: params.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }, maxRedirects: 0,
    });
    let errorKeys = [];
    if (response.status() === 422) errorKeys = Object.keys((await response.json()).errors || {});
    return { status: response.status(), errorKeys };
}
(async () => {
    fs.mkdirSync(output, { recursive: true });
    browser = await chromium.launch({ headless: true, executablePath: process.env.QA_BROWSER_EXE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', proxy: { server: 'http://127.0.0.1:9', bypass: '127.0.0.1' } });
    for (const width of [390, 1440]) {
        const context = await browser.newContext({ viewport: { width, height: 900 }, hasTouch: width === 390, locale: 'ar-LY', timezoneId: 'Africa/Tripoli', serviceWorkers: 'block' });
        await context.route('**/*', r => new URL(r.request().url()).origin === base ? r.continue() : r.abort());
        const page = await context.newPage(); page.setDefaultTimeout(15000);
        page.on('pageerror', error => errors.push(error.message));
        page.on('response', response => { if (response.status() >= 500) errors.push(response.status() + ' ' + new URL(response.url()).pathname); });
        await page.goto(base + '/login');
        await page.locator('[name=email]').fill('admin@example.test');
        await page.locator('[name=password]').fill('password123');
        await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
        await page.goto(base + '/admin/classes/create');
        const year = await page.locator('[name=academic_year_id]').inputValue();
        const subjects = await page.locator('[name="subject_ids[]"]').evaluateAll(es => es.map(e => e.value));
        assert(subjects.length >= 2);
        const name = `QA-CLOSE-${width}-${Date.now()}`;
        const values = { name, stage: 'أساسي', section: 'أ', academic_year_id: year };
        await check(`QA009 ${width}: one subject create, persisted record, reload, multi-subject edit`, async () => {
            const before = snapshot(name);
            await fill(page, values, [subjects[0]]);
            const create = await submit(page); assert.equal(create.status, 302);
            assert(/\/admin\/classes\/\d+$/.test(page.url()));
            const url = page.url(), id = Number(url.split('/').at(-1));
            let state = snapshot(name);
            assert.equal(state.total_classrooms, before.total_classrooms + 1);
            assert.deepEqual(state.matching.map(r => r.subjects), [[Number(subjects[0])]]);
            await page.goto(url + '/edit'); await page.reload();
            assert.equal(await page.locator('[name=name]').inputValue(), name);
            await fill(page, values, subjects.slice(0, 2));
            const update = await submit(page); assert.equal(update.status, 302);
            await page.goto(url + '/edit'); await page.reload();
            assert.deepEqual(await page.locator('[name="subject_ids[]"]:checked').evaluateAll(es => es.map(e => e.value).sort()), subjects.slice(0, 2).sort());
            state = snapshot(name);
            assert.equal(state.matching[0].id, id);
            assert.deepEqual(state.matching[0].subjects, subjects.slice(0, 2).map(Number).sort((a, b) => a - b));
            const layout = await page.evaluate(() => ({ viewport: innerWidth, scroll: document.documentElement.scrollWidth, dir: document.documentElement.dir }));
            assert.equal(layout.dir, 'rtl'); assert(layout.scroll <= width + 1);
            await page.screenshot({ path: path.join(output, `qa009-class-${width}.png`), fullPage: true });
            return { create, update, persisted: state.matching, layout, input: 'real rendered form; touch emulated at 390, pointer at 1440' };
        });
        await check(`QA009 ${width}: multi-subject create then remove relation and reload`, async () => {
            await page.goto(base + '/admin/classes/create');
            const multi = { ...values, name: name + '-multi' };
            await fill(page, multi, subjects.slice(0, 2));
            const create = await submit(page); assert.equal(create.status, 302);
            const url = page.url();
            assert.deepEqual(snapshot(multi.name).matching[0].subjects, subjects.slice(0, 2).map(Number).sort((a, b) => a - b));
            await page.goto(url + '/edit');
            await fill(page, multi, [subjects[1]]);
            const update = await submit(page); assert.equal(update.status, 302);
            await page.goto(url + '/edit'); await page.reload();
            const state = snapshot(multi.name);
            assert.deepEqual(state.matching[0].subjects, [Number(subjects[1])]);
            assert.deepEqual(await page.locator('[name="subject_ids[]"]:checked').evaluateAll(es => es.map(e => e.value)), [subjects[1]]);
            return { create, update, persisted: state.matching };
        });
        await check(`QA009 ${width}: invalid, missing and duplicate requests are validated without partial records`, async () => {
            await page.goto(base + '/admin/classes/create');
            await fill(page, { ...values, name: name + '-invalid', stage: '' }, [subjects[0]]);
            assert.equal(await page.locator('[name=stage]').evaluate(e => e.validity.valueMissing), true);
            const cases = [
                ['missing stage', { ...values, name: name + '-invalid', stage: '' }, 'stage'],
                ['missing year', { ...values, name: name + '-invalid', academic_year_id: '' }, 'academic_year_id'],
                ['unknown year', { ...values, name: name + '-invalid', academic_year_id: '2147483647' }, 'academic_year_id'],
                ['malformed year', { ...values, name: name + '-invalid', academic_year_id: 'not-a-year' }, 'academic_year_id'],
                ['unknown subject', { ...values, name: name + '-invalid', subject_ids: ['2147483647'] }, 'subject_ids.0'],
                ['malformed subject', { ...values, name: name + '-invalid', subject_ids: ['not-a-subject'] }, 'subject_ids.0'],
                ['oversized stage', { ...values, name: name + '-invalid', stage: 'X'.repeat(101) }, 'stage'],
                ['duplicate name+section+year', values, 'name'],
            ];
            const before = snapshot(name), outcomes = [];
            for (const [label, payload, field] of cases) {
                const outcome = await validatedPost(page, payload);
                outcomes.push({ case: label, field, ...outcome });
            }
            assert.deepEqual(snapshot(name), before);
            assert.equal(snapshot(name + '-invalid').matching.length, 0);
            assert(outcomes.every(item => item.status === 422 && item.errorKeys.includes(item.field)), JSON.stringify(outcomes));
            return { outcomes, unchangedDatabase: true };
        });
        await context.close();
    }
    results.push({ name: 'No page errors or HTTP 5xx', result: errors.length ? 'FAIL' : 'PASS', errors });
    const report = { startedAt, testedAt: new Date().toISOString(), browser: browser.version(), origin: base, fixture: path.basename(process.env.QA_BROWSER_RUN), database: snapshot('QA-CLOSE-metadata').database, historicalReport: 'NOT_REPRODUCED; current acceptance evaluated separately', results };
    fs.writeFileSync(path.join(output, 'browser-qa009-closeout.json'), JSON.stringify(report, null, 2));
    await browser.close(); process.exitCode = results.some(r => r.result !== 'PASS') ? 1 : 0;
})().catch(async error => { console.error(error.message); if (browser) await browser.close(); process.exitCode = 1; });
