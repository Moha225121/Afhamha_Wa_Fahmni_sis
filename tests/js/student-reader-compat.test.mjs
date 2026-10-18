import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Worker } from 'node:worker_threads';

// Exercise the real bundled display module AND worker with the APIs that caused
// the reader to fail on older browser engines absent from this realm.
test('student PDF modules load and parse pages without native withResolvers or URL.parse', async () => {
    Promise.withResolvers = undefined;
    URL.parse = undefined;
    // Canvas APIs are not needed for this parser test (browser rendering is tested separately).
    globalThis.DOMMatrix = class {};
    globalThis.Path2D = class {};
    const pdfjs = await import('../../public/vendor/pdfjs/legacy/student.mjs');
    assert.equal(typeof Promise.withResolvers, 'function');
    assert.equal(typeof URL.parse, 'function');
    pdfjs.GlobalWorkerOptions.workerSrc = new URL('../../public/vendor/pdfjs/legacy/student.worker.mjs', import.meta.url).href;

    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 400] /Resources << >> >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 400] /Resources << >> >>',
    ];
    let source = '%PDF-1.4\n';
    const offsets = [0];
    for (const [index, object] of objects.entries()) {
        offsets.push(source.length);
        source += `${index + 1} 0 obj\n${object}\nendobj\n`;
    }
    const xref = source.length;
    source += `xref\n0 5\n0000000000 65535 f \n${offsets.slice(1).map(offset => String(offset).padStart(10, '0') + ' 00000 n \n').join('')}trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
    const task = pdfjs.getDocument({ data: new TextEncoder().encode(source), isEvalSupported: false });
    try {
        const document = await task.promise;
        assert.equal(document.numPages, 2);
        assert.equal((await document.getPage(2)).getViewport({ scale: 1 }).width, 300);
    } finally {
        await task.destroy();
    }
    const invalid = pdfjs.getDocument({ data: new TextEncoder().encode('not a PDF'), isEvalSupported: false });
    try {
        await assert.rejects(invalid.promise, { name: 'InvalidPDFException' });
    } finally {
        await invalid.destroy();
    }
});

test('the student worker supplies compatibility APIs in its own realm', async () => {
    const worker = new Worker(`
        const { parentPort, workerData } = require('node:worker_threads');
        Promise.withResolvers = undefined;
        URL.parse = undefined;
        import(workerData).then(module => parentPort.postMessage({
            promise: typeof Promise.withResolvers,
            url: typeof URL.parse,
            handler: typeof module.WorkerMessageHandler.setup,
        })).catch(error => { throw error; });
    `, { eval: true, workerData: new URL('../../public/vendor/pdfjs/legacy/student.worker.mjs', import.meta.url).href });
    try {
        const result = await new Promise((resolve, reject) => {
            worker.once('message', resolve);
            worker.once('error', reject);
        });
        assert.deepEqual(result, { promise: 'function', url: 'function', handler: 'function' });
    } finally {
        await worker.terminate();
    }
});
