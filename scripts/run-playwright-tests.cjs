'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const tmpDir = path.join(root, '.tmp');
fs.mkdirSync(path.join(tmpDir, 'playwright-transform-cache-0'), { recursive: true });

const inputArgs = process.argv.slice(2);
const playwrightArgs = [];
const env = { ...process.env, TMPDIR: tmpDir };

for (const argument of inputArgs) {
  if (argument === '--readonly') {
    env.E2E_READONLY = '1';
    continue;
  }
  if (argument === '--staging') {
    env.E2E_ALLOW_MUTATION = 'true';
    env.PLAYWRIGHT_BASE_URL = 'http://staging.eskill.com.br';
    env.PLAYWRIGHT_SKIP_WEBSERVER = '1';
    continue;
  }
  playwrightArgs.push(argument);
}

const packageJson = require.resolve('@playwright/test/package.json', { paths: [root] });
const cli = path.join(path.dirname(packageJson), 'cli.js');
const result = spawnSync(process.execPath, [cli, 'test', ...playwrightArgs], {
  cwd: root,
  env,
  stdio: 'inherit',
  shell: false,
});

if (result.error) {
  console.error(result.error.message);
  process.exit(1);
}
process.exit(result.status ?? 1);
