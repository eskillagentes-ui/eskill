#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
  QA_STEPS,
  NetworkPolicyViolation,
  assertAccountScope,
  assertReadonlyRedirect,
  assertReadonlyRequest,
  auditUserAgent,
  createProtocolRecord,
  executeProtocolStep,
  latestScreenshotPath,
  moveCursor,
  networkPolicyDecision,
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
    assert.throws(
      () => assertReadonlyRequest(method, 'https://qa.example.test/api/action', base),
      NetworkPolicyViolation,
    );
  }
  assert.throws(() => assertReadonlyRequest('GET', 'https://evil.example/dashboard/pregao', base), /same-origin/i);
  assert.throws(() => assertReadonlyRequest('GET', 'http://qa.example.test/dashboard/pregao', base), /same-origin/i);
  assert.throws(() => assertReadonlyRequest('GET', 'data:text/plain,test', base), /protocolo/i);
  assert.throws(() => assertReadonlyRequest('GET', 'https://user:secret@qa.example.test/', base), /credenciais/i);
});

test('dependências externas conhecidas do layout são interceptadas sem egress e degradam honestamente', () => {
  const base = new URL('https://qa.example.test');
  for (const url of [
    'https://fonts.googleapis.com/css2?family=Inter',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
  ]) {
    const decision = networkPolicyDecision('GET', url, base);
    assert.strictEqual(decision.kind, 'intercept');
    assert.strictEqual(decision.critical, true);
    assert.ok(decision.contentType.length > 0);
  }
  const avatar = networkPolicyDecision('GET', 'https://ui-avatars.com/api/?name=QA', base);
  assert.strictEqual(avatar.kind, 'intercept');
  assert.strictEqual(avatar.critical, false);
  assert.throws(
    () => networkPolicyDecision('GET', 'https://unknown.example/script.js', base),
    NetworkPolicyViolation,
  );
});

test('todos os redirects HTTP são validados hop a hop e nunca podem sair da origem', () => {
  const base = new URL('https://qa.example.test');
  for (const status of [301, 302, 303, 307, 308]) {
    const target = assertReadonlyRedirect({
      status,
      method: 'GET',
      fromUrl: 'https://qa.example.test/start',
      location: `/hop-${status}`,
      baseUrl: base,
    });
    assert.strictEqual(target.href, `https://qa.example.test/hop-${status}`);
    assert.throws(() => assertReadonlyRedirect({
      status,
      method: 'GET',
      fromUrl: 'https://qa.example.test/start',
      location: 'https://egress.example/hop',
      baseUrl: base,
    }), NetworkPolicyViolation);
  }
  assert.throws(() => assertReadonlyRedirect({
    status: 302,
    method: 'POST',
    fromUrl: 'https://qa.example.test/start',
    location: '/hop',
    baseUrl: base,
  }), NetworkPolicyViolation);
  assert.throws(() => assertReadonlyRedirect({
    status: 302,
    method: 'GET',
    fromUrl: 'https://qa.example.test/start',
    location: '',
    baseUrl: base,
  }), NetworkPolicyViolation);
});

test('user agent identifica auditoria read-only e execução sem carregar segredo', () => {
  const value = auditUserAgent(RUN_ID);
  assert.match(value, /^ESKILL-Pregao-QA-ReadOnly\/1\.0 /);
  assert.match(value, new RegExp(RUN_ID));
  assert.doesNotMatch(value, /cookie|token|secret/i);
});

test('escopo da conta deve coincidir no HTML e no snapshot', () => {
  assert.doesNotThrow(() => assertAccountScope('1335', {
    success: true,
    data: { account_id: 1335 },
  }, 1335));
  assert.throws(() => assertAccountScope('9999', {
    success: true,
    data: { account_id: 1335 },
  }, 1335), /dashboard fora do escopo/i);
  assert.throws(() => assertAccountScope('1335', {
    success: true,
    data: { account_id: 9999 },
  }, 1335), /snapshot fora do escopo/i);
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

test('violação de rede emite blocked, nunca failed', async () => {
  const emitted = [];
  await assert.rejects(() => executeProtocolStep({
    runId: RUN_ID,
    sequence: 1,
    step: 'dashboard',
    action: async () => { throw new Error('request abortada'); },
    capture: async () => null,
    write: (record) => emitted.push(record),
    now: () => '2026-08-05T12:00:00.000Z',
    blocked: () => true,
  }), /etapa dashboard bloqueada/);
  assert.strictEqual(emitted.length, 1);
  assert.strictEqual(emitted[0].result, 'blocked');
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
    PREGAO_QA_ACCOUNT_ID: '1335',
  });
  assert.deepStrictEqual(config.cookie, { name: 'PHPSESSID', value: 'session-value' });
  assert.strictEqual(config.baseUrl.origin, 'https://qa.example.test');
  assert.strictEqual(config.accountId, 1335);
  assert.strictEqual(config.executablePath, '/usr/bin/google-chrome-stable');
  assert.throws(() => parseRunnerEnv({
    PREGAO_QA_BASE_URL: 'https://qa.example.test',
    PREGAO_QA_RUN_ID: RUN_ID,
    PREGAO_QA_OUTPUT_DIR: '/tmp/qa-output',
    PREGAO_QA_ACCOUNT_ID: '1335',
  }), /cookie.*env/i);
  assert.doesNotMatch(source, /process\.argv\[[2-9]\]/, 'runner não lê segredo ou destino de argv');
});

test('runner é headless, sem trace vídeo body sensível ou APIs de escrita', () => {
  assert.match(source, /from ['"]playwright['"]/);
  assert.match(source, /chromium\.launch\(\{\s*headless:\s*true,\s*executablePath:/s);
  assert.match(source, /serviceWorkers:\s*['"]block['"]/);
  assert.match(source, /userAgent:\s*auditUserAgent\(/);
  assert.match(source, /route\.fetch\(\{\s*maxRedirects:\s*0\s*\}\)/);
  assert.match(source, /pathname === ['"]\/api\/pregao\/stream['"]/);
  assert.match(source, /route\.continue\(/);
  assert.match(source, /route\.abort\(/);
  assert.doesNotMatch(source, /trace\s*:/i);
  assert.doesNotMatch(source, /video\s*:/i);
  assert.doesNotMatch(source, /postData|response\.body|\.text\(\)/);
  assert.doesNotMatch(source, /page\.request|context\.request|request\.(?:post|put|patch|delete)\s*\(/i);
  assert.doesNotMatch(source, /https?:\/\/eskill\.com\.br/i, 'runner não embute alvo de produção');
});
