import { readFileSync, realpathSync, statSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { isAbsolute, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

export const pushPublicAssets = [
    'public/app-sw.js',
    'public/parent-sw.js',
    'public/js/push-notification-display.js',
    'public/js/push-activation-status.js',
    'public/css/push-notifications.css',
];

// Explicit source allowlist: no environment, credentials, user data or runtime files.
export const pushBuildInputs = [
    'resources/js/app.js', 'resources/js/push-notifications.js', 'resources/js/push-support.js',
    'resources/js/firebase-worker.js', 'resources/css/app.css', 'vite.config.js', 'vite.push.config.js',
    'package.json', 'package-lock.json', ...pushPublicAssets,
];
const digest = (root, file) => createHash('sha256').update(readFileSync(resolve(root, file))).digest('hex');
const inputDigest = (root) => createHash('sha256').update(JSON.stringify(pushBuildInputs.map((file) => [file, digest(root, file)]))).digest('hex');
const stampFile = 'public/build/push-build.json';

function buildAsset(root, file) {
    if (typeof file !== 'string' || isAbsolute(file) || file.includes('\\') || file.includes(':')
        || file.split('/').some((part) => !part || part === '.' || part === '..')) {
        throw new Error('Build asset path is invalid; do not deploy this build.');
    }
    const directory = realpathSync(resolve(root, 'public/build'));
    const path = realpathSync(resolve(directory, file));
    const within = relative(directory, path);
    if (within === '..' || within.startsWith('..' + sep) || isAbsolute(within)) {
        throw new Error('Build asset path leaves public/build; do not deploy this build.');
    }
    if (!statSync(path).isFile()) throw new Error(`Build asset is not a regular file: ${file}; do not deploy this build.`);
    return path;
}

function manifestFiles(item) {
    if (!item || typeof item !== 'object' || Array.isArray(item)
        || (item.css !== undefined && !Array.isArray(item.css)) || (item.assets !== undefined && !Array.isArray(item.assets))) {
        throw new Error('Build manifest entry is invalid; do not deploy this build.');
    }
    return [item.file, ...(item.css || []), ...(item.assets || [])];
}

export function verifyPushBuild(root = process.cwd(), { requireStamp = true } = {}) {
    const manifest = JSON.parse(readFileSync(buildAsset(root, 'manifest.json'), 'utf8'));
    if (!manifest || typeof manifest !== 'object' || Array.isArray(manifest)) throw new Error('Build manifest is invalid; do not deploy this build.');
    const entry = manifest['resources/js/app.js']?.file;
    if (!entry || !readFileSync(buildAsset(root, entry), 'utf8').includes('school-push-config')) {
        throw new Error('Push activation is missing from the Vite app entry; do not deploy this build.');
    }
    for (const item of Object.values(manifest)) {
        for (const file of manifestFiles(item)) {
            if (!statSync(buildAsset(root, file)).size) {
                throw new Error(`Build asset is empty: ${file}; do not deploy this build.`);
            }
        }
        for (const dependency of [...(item.imports || []), ...(item.dynamicImports || [])]) {
            if (!manifest[dependency]) throw new Error(`Build dependency is missing: ${dependency}`);
        }
    }
    const worker = buildAsset(root, 'push/firebase-messaging.js');
    if (!statSync(worker).size) throw new Error('Firebase worker bundle is empty; do not deploy this build.');
    for (const asset of pushPublicAssets) {
        const stats = statSync(resolve(root, asset));
        if (!stats.isFile() || !stats.size) throw new Error(`Required public push asset is empty or not a regular file: ${asset}`);
    }
    if (requireStamp) {
        const stamp = JSON.parse(readFileSync(buildAsset(root, 'push-build.json'), 'utf8'));
        if (stamp.inputDigest !== inputDigest(root)) throw new Error('Notification build is stale for this source; rebuild before deployment.');
        const expectedFiles = buildFiles(manifest);
        if (!stamp.assets || typeof stamp.assets !== 'object' || Array.isArray(stamp.assets)
            || expectedFiles.some((file) => !Object.hasOwn(stamp.assets, file))) throw new Error('Notification build inventory is incomplete; rebuild before deployment.');
        if (Object.keys(stamp.assets).some((file) => !expectedFiles.includes(file))) throw new Error('Notification build inventory contains unexpected assets; rebuild before deployment.');
        for (const file of expectedFiles) {
            if (digest(root, file) !== stamp.assets[file]) throw new Error(`Notification build asset changed: ${file}; rebuild before deployment.`);
        }
    }
    return entry;
}

function buildFiles(manifest) {
    return [...new Set(['public/build/manifest.json', 'public/build/push/firebase-messaging.js', ...pushPublicAssets,
        ...Object.values(manifest).flatMap((item) => manifestFiles(item).map((file) => 'public/build/' + file)),
    ])];
}

export function recordPushBuild(root = process.cwd()) {
    verifyPushBuild(root, { requireStamp: false });
    const manifest = JSON.parse(readFileSync(resolve(root, 'public/build/manifest.json'), 'utf8'));
    const assets = Object.fromEntries(buildFiles(manifest).map((file) => [file, digest(root, file)]));
    writeFileSync(resolve(root, stampFile), JSON.stringify({ inputDigest: inputDigest(root), assets }, null, 2) + '\n');
    return verifyPushBuild(root);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    console.log(`Push build verified: ${process.argv.includes('--record') ? recordPushBuild() : verifyPushBuild()} and push/firebase-messaging.js`);
}
