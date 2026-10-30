import { readdirSync, realpathSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const root = fileURLToPath(new URL('../', import.meta.url));
const files = new Map();
for (const folder of ['tests/Js', 'tests/js']) {
    const directory = resolve(root, folder);
    if (!existsSync(directory)) continue;
    for (const name of readdirSync(directory).filter(name => name.endsWith('.test.mjs'))) {
        const path = realpathSync(resolve(directory, name));
        files.set(process.platform === 'win32' ? path.toLowerCase() : path, path);
    }
}
if (!files.size) throw new Error('No portal JavaScript tests found.');
const result = spawnSync(process.execPath, ['--test', ...files.values()], { cwd: root, stdio: 'inherit' });
if (result.error) throw result.error;
process.exit(result.status ?? 1);
