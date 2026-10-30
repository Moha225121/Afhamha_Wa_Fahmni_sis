import assert from 'node:assert/strict';
import test from 'node:test';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, copyFileSync, chmodSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { spawnSync } from 'node:child_process';
import { pushBuildInputs, recordPushBuild, verifyPushBuild } from '../../scripts/verify-push-build.mjs';

const bash = process.platform === 'win32' ? join(process.env.ProgramFiles || 'C:/Program Files', 'Git/bin/bash.exe') : '/bin/bash';
const slash = (value) => value.replaceAll('\\', '/');
const shellQuote = (value) => "'" + slash(value).replaceAll("'", "'\\''") + "'";

function fixture(withNpm = true) {
    const root = mkdtempSync(join(tmpdir(), 'school-push-deploy-'));
    const put = (file, content) => { const path = join(root, file); mkdirSync(dirname(path), { recursive: true }); writeFileSync(path, content); return path; };
    for (const file of pushBuildInputs) put(file, 'synthetic source');
    put('package.json', JSON.stringify({ type: 'module' }));
    put('package-lock.json', '{}');
    put('public/build/manifest.json', JSON.stringify({ 'resources/js/app.js': { file: 'assets/app.js' } }));
    put('public/build/assets/app.js', 'school-push-config');
    put('public/build/push/firebase-messaging.js', 'synthetic worker');
    copyFileSync(new URL('../../scripts/verify-push-build.mjs', import.meta.url), put('scripts/verify-push-build.mjs', ''));
    copyFileSync(new URL('../../scripts/ensure-push-build.sh', import.meta.url), put('scripts/ensure-push-build.sh', ''));
    const executable = (file, content) => { const path = put('bin/' + file, '#!/bin/bash\n' + content + '\n'); chmodSync(path, 0o755); };
    executable('dirname', 'printf "%s\\n" "${1%/*}"');
    executable('node', 'exec ' + shellQuote(process.execPath) + ' "$@"');
    const builder = put('builder.mjs', `import {writeFileSync} from 'node:fs';import {recordPushBuild} from './scripts/verify-push-build.mjs';writeFileSync('public/build/assets/app.js','school-push-config');writeFileSync('public/build/push/firebase-messaging.js','worker');recordPushBuild();`);
    if (withNpm) executable('npm', 'printf "%s\\n" "$*" >> "$TEST_NPM_LOG"\nif [ "$*" = "run build" ]; then exec ' + shellQuote(process.execPath) + ' "$TEST_BUILDER"; fi');
    const run = (...args) => spawnSync(bash, [join(root, 'scripts/ensure-push-build.sh'), ...args], {
        encoding: 'utf8', env: { ...process.env, PATH: slash(join(root, 'bin')), TEST_NPM_LOG: join(root, 'npm.log'), TEST_BUILDER: builder },
    });
    return { root, put, run, clean: () => rmSync(root, { recursive: true, force: true }) };
}

test('current Git revision with a verified build needs no npm installation or rebuild', () => {
    const f = fixture();
    try { recordPushBuild(f.root); assert.equal(f.run().status, 0); assert.throws(() => readFileSync(join(f.root, 'npm.log')), /ENOENT/); } finally { f.clean(); }
});

for (const defect of ['empty app', 'missing worker']) test(`same-revision ${defect} is actually rebuilt and verified`, () => {
    const f = fixture();
    try {
        recordPushBuild(f.root);
        if (defect === 'empty app') f.put('public/build/assets/app.js', '');
        else rmSync(join(f.root, 'public/build/push/firebase-messaging.js'));
        assert.equal(f.run().status, 0);
        assert.equal(readFileSync(join(f.root, 'npm.log'), 'utf8').trim(), 'ci --ignore-scripts\nrun build');
        assert.equal(verifyPushBuild(f.root), 'assets/app.js');
    } finally { f.clean(); }
});

test('missing npm is a failed deployment rather than a silently skipped frontend build', () => {
    const f = fixture(false);
    try { const result = f.run('--rebuild'); assert.notEqual(result.status, 0); assert.match(result.stderr, /npm is unavailable/); } finally { f.clean(); }
});
