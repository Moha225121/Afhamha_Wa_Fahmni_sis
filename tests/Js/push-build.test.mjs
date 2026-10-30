import assert from 'node:assert/strict';
import test from 'node:test';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, rmSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { pushBuildInputs, pushPublicAssets, recordPushBuild, verifyPushBuild } from '../../scripts/verify-push-build.mjs';

test('deployment gate rejects the observed empty entry and missing worker', () => {
    const root = mkdtempSync(join(tmpdir(), 'school-push-build-'));
    try {
        mkdirSync(join(root, 'public/build/assets'), { recursive: true });
        mkdirSync(join(root, 'public/build/push'));
        writeFileSync(join(root, 'public/build/manifest.json'), JSON.stringify({ 'resources/js/app.js': { file: 'assets/app.js' } }));
        writeFileSync(join(root, 'public/build/assets/app.js'), '');
        assert.throws(() => verifyPushBuild(root), /activation is missing/);
        writeFileSync(join(root, 'public/build/assets/app.js'), 'school-push-config');
        assert.throws(() => verifyPushBuild(root), /ENOENT/);
        writeFileSync(join(root, 'public/build/push/firebase-messaging.js'), 'worker');
        assert.throws(() => verifyPushBuild(root), /ENOENT/);
        for (const asset of pushPublicAssets) {
            mkdirSync(dirname(join(root, asset)), { recursive: true });
            writeFileSync(join(root, asset), 'public asset');
        }
        for (const input of pushBuildInputs) {
            mkdirSync(dirname(join(root, input)), { recursive: true });
            writeFileSync(join(root, input), 'synthetic source');
        }
        assert.throws(() => verifyPushBuild(root), /ENOENT/);
        recordPushBuild(root);
        assert.equal(verifyPushBuild(root), 'assets/app.js');
        writeFileSync(join(root, 'resources/js/push-notifications.js'), 'new client');
        assert.throws(() => verifyPushBuild(root), /stale/);
        recordPushBuild(root);
        writeFileSync(join(root, 'public/build/assets/app.js'), 'school-push-config; changed bundle');
        assert.throws(() => verifyPushBuild(root), /asset changed/);
        recordPushBuild(root);
        const manifest = { 'resources/js/app.js': { file: 'assets/app.js', dynamicImports: ['firebase/messaging'] } };
        writeFileSync(join(root, 'public/build/manifest.json'), JSON.stringify(manifest));
        assert.throws(() => verifyPushBuild(root), /dependency is missing/);
        manifest['firebase/messaging'] = { file: 'assets/messaging.js' };
        writeFileSync(join(root, 'public/build/manifest.json'), JSON.stringify(manifest));
        assert.throws(() => verifyPushBuild(root), /ENOENT/);
        writeFileSync(join(root, 'public/build/assets/messaging.js'), '');
        assert.throws(() => verifyPushBuild(root), /asset is empty/);
        writeFileSync(join(root, 'public/build/assets/messaging.js'), 'messaging');
        recordPushBuild(root);
        assert.equal(verifyPushBuild(root), 'assets/app.js');
        writeFileSync(join(root, 'public/build/push/firebase-messaging.js'), '');
        assert.throws(() => verifyPushBuild(root), /worker bundle is empty/);
    } finally {
        rmSync(root, { recursive: true, force: true });
    }
});

function buildFixture() {
    const root = mkdtempSync(join(tmpdir(), 'school-push-inventory-'));
    const put = (file, contents) => {
        mkdirSync(dirname(join(root, file)), { recursive: true });
        writeFileSync(join(root, file), contents);
    };
    for (const input of pushBuildInputs) put(input, 'synthetic source');
    const manifest = { 'resources/js/app.js': { file: 'assets/app.js' } };
    const saveManifest = () => put('public/build/manifest.json', JSON.stringify(manifest));
    put('public/build/assets/app.js', 'school-push-config');
    put('public/build/push/firebase-messaging.js', 'worker');
    saveManifest();
    return { root, put, manifest, saveManifest, clean: () => rmSync(root, { recursive: true, force: true }) };
}

for (const field of ['css', 'assets']) test(`cannot record or verify an empty manifest ${field} dependency`, () => {
    const fixture = buildFixture();
    try {
        const dependency = `assets/dependency.${field === 'css' ? 'css' : 'woff2'}`;
        fixture.manifest['resources/js/app.js'][field] = [dependency];
        fixture.saveManifest();
        fixture.put('public/build/' + dependency, 'valid dependency');
        recordPushBuild(fixture.root);
        fixture.put('public/build/' + dependency, '');
        assert.throws(() => verifyPushBuild(fixture.root), /asset is empty/);
        assert.throws(() => recordPushBuild(fixture.root), /asset is empty/);
    } finally { fixture.clean(); }
});

for (const field of ['file', 'css', 'assets']) test(`manifest ${field} rejects a directory in place of a regular file`, () => {
    const fixture = buildFixture();
    try {
        const directory = 'assets/not-a-file';
        mkdirSync(join(fixture.root, 'public/build', directory));
        if (field === 'file') fixture.manifest['extra.js'] = { file: directory };
        else fixture.manifest['resources/js/app.js'][field] = [directory];
        fixture.saveManifest();
        assert.throws(() => recordPushBuild(fixture.root), /not a regular file/);
    } finally { fixture.clean(); }
});

for (const field of ['file', 'css', 'assets']) test(`manifest ${field} rejects parent traversal and absolute paths before reading them`, () => {
    const fixture = buildFixture();
    try {
        fixture.put('public/outside.js', 'school-push-config');
        for (const path of ['../outside.js', 'assets/../../outside.js', join(fixture.root, 'public/outside.js'), '..\\outside.js']) {
            fixture.manifest['resources/js/app.js'][field] = field === 'file' ? path : [path];
            fixture.saveManifest();
            assert.throws(() => recordPushBuild(fixture.root), /asset path is invalid/);
        }
    } finally { fixture.clean(); }
});

test('manifest paths cannot escape public/build through a linked directory', () => {
    const fixture = buildFixture();
    try {
        fixture.put('public/outside/dependency.css', 'synthetic dependency');
        symlinkSync(join(fixture.root, 'public/outside'), join(fixture.root, 'public/build/linked'), process.platform === 'win32' ? 'junction' : 'dir');
        fixture.manifest['resources/js/app.js'].css = ['linked/dependency.css'];
        fixture.saveManifest();
        assert.throws(() => recordPushBuild(fixture.root), /asset path leaves public\/build/);
    } finally { fixture.clean(); }
});

test('stamp rejects unexpected entries before attempting to read them', () => {
    const fixture = buildFixture();
    try {
        recordPushBuild(fixture.root);
        const original = readFileSync(join(fixture.root, 'public/build/push-build.json'), 'utf8');
        for (const path of ['../outside.js', 'public/build/../outside.js', join(fixture.root, 'absent.js'), 'public/build/extra.js']) {
            const stamp = JSON.parse(original);
            stamp.assets[path] = 'untrusted inventory entry';
            fixture.put('public/build/push-build.json', JSON.stringify(stamp));
            assert.throws(() => verifyPushBuild(fixture.root), /inventory contains unexpected assets/);
        }
    } finally { fixture.clean(); }
});
