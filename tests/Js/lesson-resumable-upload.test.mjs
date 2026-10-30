import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { webcrypto, createHash } from 'node:crypto';
import vm from 'node:vm';

function environment() {
    const storage = new Map(); const states = new Map(); const sent = []; const requests = [];
    let loseAcknowledgement = false; let failStatus = null; let failBody = null;
    class XHR {
        constructor() { this.upload = {}; this.ended = false; }
        open(method, url) { this.method = method; this.url = url; }
        setRequestHeader() {}
        getResponseHeader() { return null; }
        abort() { if (!this.ended) { this.ended = true; this.onabort?.(); } }
        async send(body) {
            await Promise.resolve();
            if (this.ended) return;
            requests.push({method: this.method, url: this.url, body});
            if (failStatus === 0) { this.ended = true; this.onerror(); return; }
            if (failStatus !== null) { this.status = failStatus; this.responseText = failBody === null ? '<html>proxy rejected</html>' : JSON.stringify(failBody); this.ended = true; this.onload(); return; }
            const [, id, action] = this.url.replace('/uploads', '').split('/');
            let state = states.get(id); let response;
            if (this.method === 'POST' && !id) {
                const data = JSON.parse(body);
                state = states.get(data.upload_id) || { upload_id: data.upload_id, name: data.name, size: data.size, received_bytes: 0, chunk_size: 8, hashes: [], status: 'uploading' };
                states.set(state.upload_id, state); response = state;
            } else if (!state) { this.status = 404; response = {}; }
            else if (action === 'chunks') {
                const index = Number(body.get('index')); const chunk = Buffer.from(await body.get('chunk').arrayBuffer());
                sent.push(index);
                if (!state.hashes[index]) {
                    state.hashes[index] = createHash('sha256').update(chunk).digest('hex'); state.received_bytes += chunk.length;
                }
                if (loseAcknowledgement) { loseAcknowledgement = false; this.ended = true; this.onerror(); return; }
                response = state;
            } else if (action === 'complete') { state.status = 'ready'; response = state; }
            else if (this.method === 'DELETE') { state.status = 'cancelled'; response = state; }
            else response = state;
            if (this.ended) return;
            this.status ||= 200; this.responseText = JSON.stringify(response); this.ended = true; this.onload();
        }
    }
    const sandbox = {
        window: {}, crypto: webcrypto, Blob, FormData, XMLHttpRequest: XHR,
        localStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) },
        setTimeout: resolve => queueMicrotask(resolve),
    };
    vm.runInNewContext(readFileSync(new URL('../../public/js/lesson-resumable-upload.js', import.meta.url), 'utf8'), sandbox);
    const file = new File(['%PDF-1.4 abcdefghijklmnopqrstuv'], 'lesson.pdf');
    const makeClient = (options = {}) => new sandbox.window.LessonResumableUpload({file, base:'/uploads', user:'1', csrf:'test', onStatus:()=>{}, onProgress:()=>{}, ...options});
    return { makeClient, storage, states, sent, requests, loseNext:()=>{loseAcknowledgement=true;}, fail:(status,body=null)=>{failStatus=status;failBody=body;} };
}

test('permanent server setup failures stop immediately and show safe diagnostic codes', async () => {
    for (const code of ['UPLOAD_DATABASE_SCHEMA', 'UPLOAD_DATABASE_PERMISSION', 'UPLOAD_STORAGE_UNAVAILABLE', 'UPLOAD_VIDEO_SERVICE_UNAVAILABLE', 'UPLOAD_SERVER_ERROR']) {
        const env = environment(); const statuses = [];
        env.fail(503, {code, retryable:false, reference:'550e8400-e29b-41d4-a716-446655440000', message:'SQL password=secret /private/server/path'});
        await assert.rejects(env.makeClient({onStatus:message=>statuses.push(message)}).run(), error => {
            assert.equal(error.status, 503);
            assert.ok(error.message.includes(code));
            assert.ok(error.message.includes('550e8400-e29b-41d4-a716-446655440000'));
            assert.doesNotMatch(error.message, /password|secret|\/private/);
            return true;
        });
        assert.equal(env.requests.length, 1);
        assert.equal(statuses.length, 0);
        assert.equal(env.sent.length, 0);
    }
});

test('lost chunk acknowledgement retries the same index without appending it twice', async () => {
    const env = environment(); env.loseNext();
    const result = await env.makeClient().run();
    assert.equal(result.status, 'ready'); assert.equal(result.received_bytes, result.size);
    assert.deepEqual(env.sent.slice(0, 3), [0, 0, 1]);
    assert.equal(env.states.size, 1);
});

test('completion allows the maximum bounded verification deadline plus overhead', async () => {
    const env = environment(); const client = env.makeClient();
    const state = await client.request('POST', '', {upload_id: 'deadline', size: 1});
    assert.equal(client.xhr.timeout, 180000);
    await client.request('POST', '/' + state.upload_id + '/complete');
    assert.equal(client.xhr.timeout, 360000);
});

test('a new page resumes from the confirmed offset without resending completed chunks', async () => {
    const env = environment(); let first;
    first = env.makeClient({onProgress:()=>first.pause()});
    await assert.rejects(first.run(), /إيقاف/);
    assert.deepEqual(env.sent, [0]);
    const result = await env.makeClient().run();
    assert.equal(result.status, 'ready'); assert.deepEqual(env.sent, [0, 1, 2, 3]);
});

test('a reselected file with changed completed bytes is rejected', async () => {
    const env = environment(); let first;
    first = env.makeClient({onProgress:()=>first.pause()});
    await assert.rejects(first.run());
    const resumed = env.makeClient(); resumed.state = [...env.states.values()][0];
    resumed.file = new File(['%PDF-1.4 Zbcdefghijklmnopqrstuv'], 'lesson.pdf');
    // Change a completed byte but keep the input fingerprint lookup out of the test.
    resumed.state.hashes[0] = '0'.repeat(64);
    await assert.rejects(resumed.run(), /مختلف/);
    assert.deepEqual(env.sent, [0]);
});

test('HTML 413 and expired-session 419 responses have actionable errors without retries', async () => {
    for (const status of [413, 419]) {
        const env = environment(); env.fail(status);
        await assert.rejects(env.makeClient().run(), error => error.status === status && error.message.includes(status === 413 ? 'حجم' : 'الجلسة'));
        assert.equal(env.sent.length, 0);
    }
});

test('an unavailable media service explains how to recover after bounded retries', async () => {
    const env = environment(); env.fail(503); let statuses = 0;
    await assert.rejects(env.makeClient({onStatus:()=>statuses++}).run(), error => error.status === 503 && error.message.includes('غير متاحة مؤقتًا'));
    assert.equal(statuses, 4);
});

test('immediate HTTP 500 at initialization stops after four retries without blaming connectivity or sending chunks', async () => {
    const env = environment(); env.fail(500); const statuses = [];
    await assert.rejects(env.makeClient({onStatus:message=>statuses.push(message)}).run(), error => {
        assert.equal(error.status, 500);
        assert.match(error.message, /خطأ في خادم الرفع/);
        assert.doesNotMatch(error.message, /حفظ الجزء|الاتصال/);
        return true;
    });
    assert.equal(env.requests.length, 5);
    assert.equal(statuses.length, 4);
    assert.deepEqual(statuses.map(message => message.match(/([1-4])\/4/)[1]), ['1', '2', '3', '4']);
    assert.ok(statuses.every(message => message.includes('على الخادم') && !message.includes('الاتصال')));
    assert.ok(env.requests.every(request => request.method === 'POST' && request.url === '/uploads'));
    assert.equal(new Set(env.requests.map(request => JSON.parse(request.body).upload_id)).size, 1);
    assert.equal(env.states.size, 0);
    assert.equal(env.sent.length, 0);
});

test('retry feedback distinguishes connection failures, throttling, server timeouts and unavailable servers', async () => {
    for (const [status, reason] of [[0, /انقطع الاتصال/], [429, /طلبات كثيرة/], [408, /مهلة الخادم/], [504, /مهلة الخادم/], [502, /على الخادم/], [503, /على الخادم/]]) {
        const env = environment(); env.fail(status); const statuses = [];
        await assert.rejects(env.makeClient({onStatus:message=>statuses.push(message)}).run(), error => error.status === status);
        assert.equal(env.requests.length, 5);
        assert.equal(statuses.length, 4);
        assert.ok(statuses.every(message => reason.test(message)), 'retry reason for HTTP status ' + status);
        if (status !== 0) assert.ok(statuses.every(message => !message.includes('الاتصال')));
        assert.equal(env.sent.length, 0);
    }
});

test('a file already attached to another lesson gets a new independent upload', async () => {
    const env = environment(); const first = await env.makeClient().run();
    env.states.get(first.upload_id).status = 'attached';
    const second = await env.makeClient().run();
    assert.notEqual(first.upload_id, second.upload_id); assert.equal(env.states.size, 2);
});
