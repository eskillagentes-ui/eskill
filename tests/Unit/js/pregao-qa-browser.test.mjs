#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
  QA_STEPS,
  assertReadonlyRequest,
  createProtocolRecord,
  executeProtocolStep,
  latestScreenshotPath,
  moveCursor,
  parseRunnerEnv,
} from '../../../bin/pregao-qa-browser.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const runnerPath = path.resolve(__dirname, '../../../bin/pregao-qa-browser.mjs');
const source = fs.readFileSync(runnerPath, 'utf8');
const RUN_ID = '123e4567-e89b-42d3-a456-426614174000';

test('network guard aceita somente same-origin GET HEAD OPTIONS', () => {
  const base = new URL('https://qa.example.test');
  for (const method of ['GET', 'HEAD', 'OPTIONS', 'get']) {
    assert.doesNotThrow(() => assertReadonlyRequest(method, 'https://qa.example.test/dashboard/pregao', base));
  }
  for (const method of ['POST', 'PUT', 'PATCH', 'DELETE', 'CONNECT', 'TRACE']) {
    assert.throws(() => assertReadonlyRequest(method, 'https://qa.example.test/api/action', base), /método bloqueado/i);
  }
  assert.throws(() => assertReadonlyRequest('GET', 'https://evil.example/dashboard/pregao', base), /same-origin/i);
  assert.throws(() => assertReadonlyRequest('GET', 'http://qa.example.test/dashboard/pregao', base), /same-origin/i);
  assert.throws(() => assertReadonlyRequest('GET', 'data:text/plain,test', base), /protocolo/i);
  assert.throws(() => assertReadonlyRequest('GET', 'https://user:secret@qa.example.test/', base), /credenciais/i);
});

test('protocolo possui chaves exatas, etapa allowlisted e resultado real', () => {
  const record = createProtocolRecord({
    runId: RUN_ID,
    sequence: 2,
    step: 'snapshot',
    result: 'running',
    screenshot: 'latest.png',
    cursor: { x: 320, y: 180 },
    observedAt: '2026-08-05T12:00:00.000Z',
  });
  assert.deepStrictEqual(Object.keys(record), [
    'run_id', 'sequence', 'step', 'result', 'screenshot', 'cursor', 'observed_at'
  ]);
  assert.deepStrictEqual(record, {
    run_id: RUN_ID,
    sequence: 2,
    step: 'snapshot',
    result: 'running',
    screenshot: 'latest.png',
    cursor: { x: 320, y: 180 },
    observed_at: '2026-08-05T12:00:00.000Z',
  });
  assert.ok(QA_STEPS.includes('dashboard'));
  assert.ok(QA_STEPS.includes('event_explorer'));
  assert.throws(() => createProtocolRecord({
    runId: RUN_ID, sequence: 1, step: 'shell', result: 'passed',
    screenshot: null, cursor: null, observedAt: '2026-08-05T12:00:00.000Z'
  }), /etapa/i);
  assert.throws(() => createProtocolRecord({
    runId: RUN_ID, sequence: 1, step: 'dashboard', result: 'invented',
    screenshot: null, cursor: null, observedAt: '2026-08-05T12:00:00.000Z'
  }), /resultado/i);
});

test('falha em qualquer ponto da etapa ainda emite uma linha failed exata', async () => {
  const emitted = [];
  await assert.rejects(() => executeProtocolStep({
    runId: RUN_ID,
    sequence: 4,
    step: 'event_explorer',
    action: async () => { throw new Error('elemento ausente'); },
    capture: async () => 'latest.png',
    write: (record) => emitted.push(record),
    now: () => '2026-08-05T12:00:00.000Z',
  }), /etapa event_explorer falhou/);
  assert.strictEqual(emitted.length, 1);
  assert.deepStrictEqual(emitted[0], {
    run_id: RUN_ID,
    sequence: 4,
    step: 'event_explorer',
    result: 'failed',
    screenshot: 'latest.png',
    cursor: null,
    observed_at: '2026-08-05T12:00:00.000Z',
  });
});

test('screenshot sobrescreve somente latest.png da execução', () => {
  const file = latestScreenshotPath('/tmp/qa-run', 'event_explorer');
  assert.strictEqual(file.absolute, path.join('/tmp/qa-run', 'latest.png'));
  assert.strictEqual(file.protocol, 'latest.png');
  assert.throws(() => latestScreenshotPath('/tmp/qa-run', '../escape'), /etapa/i);
});

test('overlay recebe exatamente a coordenada usada por page.mouse', async () => {
  const calls = [];
  const page = {
    mouse: { async move(x, y) { calls.push({ kind: 'mouse', x, y }); } },
    async evaluate(fn, value) { calls.push({ kind: 'overlay', fn: String(fn), value }); }
  };
  const cursor = await moveCursor(page, 411, 237);
  assert.deepStrictEqual(cursor, { x: 411, y: 237 });
  assert.deepStrictEqual(calls[0], { kind: 'mouse', x: 411, y: 237 });
  assert.deepStrictEqual(calls[1].value, { x: 411, y: 237 });
  assert.match(calls[1].fn, /position.*fixed/s);
  assert.match(calls[1].fn, /pointerEvents.*none/s);
});

test('sessão é aceita somente por env e configuração não contém segredo', () => {
  const config = parseRunnerEnv({
    PREGAO_QA_BASE_URL: 'https://qa.example.test',
    PREGAO_QA_RUN_ID: RUN_ID,
    PREGAO_QA_SESSION_COOKIE: 'PHPSESSID=session-value',
    PREGAO_QA_OUTPUT_DIR: '/tmp/qa-output',
  });
  assert.deepStrictEqual(config.cookie, { name: 'PHPSESSID', value: 'session-value' });
  assert.strictEqual(config.baseUrl.origin, 'https://qa.example.test');
  assert.strictEqual(config.executablePath, '/usr/bin/google-chrome-stable');
  assert.throws(() => parseRunnerEnv({
    PREGAO_QA_BASE_URL: 'https://qa.example.test',
    PREGAO_QA_RUN_ID: RUN_ID,
    PREGAO_QA_OUTPUT_DIR: '/tmp/qa-output',
  }), /cookie.*env/i);
  assert.doesNotMatch(source, /process\.argv\[[2-9]\]/, 'runner não lê segredo ou destino de argv');
});

test('runner é headless, sem trace vídeo body sensível ou APIs de escrita', () => {
  assert.match(source, /from ['"]playwright['"]/);
  assert.match(source, /chromium\.launch\(\{\s*headless:\s*true,\s*executablePath:/s);
  assert.match(source, /serviceWorkers:\s*['"]block['"]/);
  assert.match(source, /route\.abort\(/);
  assert.doesNotMatch(source, /trace\s*:/i);
  assert.doesNotMatch(source, /video\s*:/i);
  assert.doesNotMatch(source, /postData|response\.body|\.text\(\)/);
  assert.doesNotMatch(source, /page\.request|context\.request|request\.(?:post|put|patch|delete)\s*\(/i);
  assert.doesNotMatch(source, /https?:\/\/eskill\.com\.br/i, 'runner não embute alvo de produção');
});
