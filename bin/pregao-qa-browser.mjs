#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';

export const QA_STEPS = Object.freeze([
  'dashboard',
  'snapshot',
  'realtime',
  'event_explorer',
  'console_http',
]);
const QA_RESULTS = new Set(['running', 'passed', 'failed', 'blocked']);
const READONLY_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);
const REDIRECT_STATUSES = new Set([301, 302, 303, 307, 308]);
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const SANDBOX_SERVICE_WORKER_ERROR = "Failed to read the 'serviceWorker' property from 'Navigator': Service worker is disabled because the context is sandboxed and lacks the 'allow-same-origin' flag.";

export class NetworkPolicyViolation extends Error {}

const EMPTY_CSS_STUB = '/* recurso visual externo substituído localmente no QA read-only */';
const CHART_JS_STUB = `'use strict';
(function (global) {
  function Chart(item, config) {
    this.canvas = item || null;
    this.config = config || {};
    this.data = this.config.data || {};
    this.options = this.config.options || {};
  }
  Chart.prototype.update = function () {};
  Chart.prototype.destroy = function () {};
  Chart.prototype.resize = function () {};
  Chart.prototype.reset = function () {};
  Chart.register = function () {};
  Chart.unregister = function () {};
  Chart.getChart = function () { return undefined; };
  Chart.defaults = {};
  Chart.instances = {};
  Chart.version = '4.4.7-qa-local-stub';
  Chart.__pregaoQaLocalStub = true;
  global.Chart = Chart;
})(window);`;
const BOOTSTRAP_JS_STUB = `'use strict';
(function (global) {
  function component(name) {
    var property = '__pregaoQaBootstrap' + name;
    function Component(element) {
      this._element = element || null;
      if (this._element && typeof this._element === 'object') this._element[property] = this;
    }
    Component.prototype.show = function () {};
    Component.prototype.hide = function () {};
    Component.prototype.toggle = function () {};
    Component.prototype.dispose = function () {};
    Component.getInstance = function (element) { return element && element[property] ? element[property] : null; };
    Component.getOrCreateInstance = function (element) { return Component.getInstance(element) || new Component(element); };
    return Component;
  }
  global.bootstrap = {
    Collapse: component('Collapse'),
    Modal: component('Modal'),
    Toast: component('Toast'),
    Tooltip: component('Tooltip')
  };
  global.bootstrap.__pregaoQaLocalStub = true;
})(window);`;

function parseHttpUrl(rawValue, label) {
  let target;
  try {
    target = new URL(rawValue);
  } catch (error) {
    throw new NetworkPolicyViolation(`${label}: URL inválida`);
  }
  if (!['http:', 'https:'].includes(target.protocol)) throw new NetworkPolicyViolation(`${label}: protocolo bloqueado`);
  if (target.username || target.password) throw new NetworkPolicyViolation(`${label}: credenciais embutidas bloqueadas`);
  return target;
}

export function assertReadonlyRequest(method, rawUrl, baseUrl) {
  const target = parseHttpUrl(rawUrl, 'request');
  if (target.origin !== baseUrl.origin) throw new NetworkPolicyViolation('request fora de same-origin');
  const normalizedMethod = String(method).toUpperCase();
  if (!READONLY_METHODS.has(normalizedMethod)) throw new NetworkPolicyViolation(`método bloqueado: ${normalizedMethod}`);
  return target;
}

export function networkPolicyDecision(method, rawUrl, baseUrl) {
  const target = parseHttpUrl(rawUrl, 'request');
  const normalizedMethod = String(method).toUpperCase();
  if (target.origin === baseUrl.origin) {
    return { kind: 'same-origin', target: assertReadonlyRequest(normalizedMethod, target.href, baseUrl) };
  }
  if (normalizedMethod !== 'GET') throw new NetworkPolicyViolation(`método bloqueado: ${normalizedMethod}`);

  const known = [
    ['fonts.googleapis.com', /^\/css2$/, 'text/css; charset=utf-8', EMPTY_CSS_STUB],
    ['cdn.jsdelivr.net', /^\/npm\/bootstrap-icons@1\.11\.0\/font\/bootstrap-icons\.css$/, 'text/css; charset=utf-8', EMPTY_CSS_STUB],
    ['cdn.jsdelivr.net', /^\/npm\/bootstrap@5\.3\.0\/dist\/css\/bootstrap\.min\.css$/, 'text/css; charset=utf-8', EMPTY_CSS_STUB],
    ['cdn.jsdelivr.net', /^\/npm\/chart\.js@4\.4\.7\/dist\/chart\.umd\.min\.js$/, 'application/javascript; charset=utf-8', CHART_JS_STUB],
    ['cdnjs.cloudflare.com', /^\/ajax\/libs\/Chart\.js\/4\.4\.7\/chart\.umd\.min\.js$/, 'application/javascript; charset=utf-8', CHART_JS_STUB],
    ['cdn.jsdelivr.net', /^\/npm\/bootstrap@5\.3\.0\/dist\/js\/bootstrap\.bundle\.min\.js$/, 'application/javascript; charset=utf-8', BOOTSTRAP_JS_STUB],
    ['ui-avatars.com', /^\/api\/$/, 'image/svg+xml; charset=utf-8', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'],
  ];
  const match = known.find(([host, pathname]) => target.hostname === host && pathname.test(target.pathname));
  if (!match) throw new NetworkPolicyViolation('egress externo inesperado');
  const contentType = match[2];
  return {
    kind: 'intercept',
    critical: false,
    contentType,
    body: match[3],
  };
}

export function assertReadonlyRedirect({ status, method, fromUrl, location, baseUrl }) {
  if (!REDIRECT_STATUSES.has(status) || typeof location !== 'string' || location.length === 0) {
    throw new NetworkPolicyViolation('redirect inválido');
  }
  const source = assertReadonlyRequest(method, fromUrl, baseUrl);
  let target;
  try {
    target = new URL(location, source);
  } catch (error) {
    throw new NetworkPolicyViolation('redirect inválido');
  }
  assertReadonlyRequest(method, target.href, baseUrl);
  return target;
}

export function auditUserAgent(runId) {
  assertUuid(runId, 'run_id');
  return `ESKILL-Pregao-QA-ReadOnly/1.0 (same-origin; run=${runId})`;
}

export function assertAccountScope(renderedAccountId, snapshotBody, expectedAccountId) {
  if (!Number.isInteger(expectedAccountId) || expectedAccountId < 1) {
    throw new Error('account_id esperado inválido');
  }
  if (String(renderedAccountId) !== String(expectedAccountId)) {
    throw new Error('dashboard fora do escopo da conta');
  }
  if (!snapshotBody || typeof snapshotBody !== 'object' || snapshotBody.success !== true
    || !snapshotBody.data || snapshotBody.data.account_id !== expectedAccountId) {
    throw new Error('snapshot fora do escopo da conta');
  }
}

function assertReadonlyWebSocket(rawUrl, baseUrl) {
  let target;
  try {
    target = new URL(rawUrl);
  } catch (error) {
    throw new Error('WebSocket: URL inválida');
  }
  if (!['ws:', 'wss:'].includes(target.protocol)) throw new Error('WebSocket: protocolo bloqueado');
  if (target.username || target.password) throw new Error('WebSocket: credenciais embutidas bloqueadas');
  const expectedProtocol = baseUrl.protocol === 'https:' ? 'wss:' : 'ws:';
  if (target.protocol !== expectedProtocol || target.host !== baseUrl.host) {
    throw new Error('WebSocket fora de same-origin');
  }
}

function assertUuid(value, label) {
  if (typeof value !== 'string' || !UUID_RE.test(value)) throw new Error(`${label}: UUID inválido`);
}

function assertStep(step) {
  if (!QA_STEPS.includes(step)) throw new Error('etapa fora da allowlist');
}

export function latestScreenshotPath(outputRoot, step) {
  assertStep(step);
  return {
    absolute: path.join(outputRoot, 'latest.png'),
    protocol: 'latest.png',
  };
}

export function createProtocolRecord({ runId, sequence, step, result, screenshot, cursor, observedAt }) {
  assertUuid(runId, 'run_id');
  assertStep(step);
  if (!Number.isInteger(sequence) || sequence < 1) throw new Error('sequence inválida');
  if (!QA_RESULTS.has(result)) throw new Error('resultado inválido');
  if (screenshot !== null && screenshot !== 'latest.png') throw new Error('screenshot inválido');
  if (cursor !== null && (!cursor || !Number.isFinite(cursor.x) || !Number.isFinite(cursor.y))) {
    throw new Error('cursor inválido');
  }
  if (typeof observedAt !== 'string' || Number.isNaN(Date.parse(observedAt))) throw new Error('observed_at inválido');
  return {
    run_id: runId,
    sequence,
    step,
    result,
    screenshot,
    cursor,
    observed_at: observedAt,
  };
}

export async function executeProtocolStep({
  runId, sequence, step, action, capture, write, now, terminal = false, blocked = () => false,
}) {
  let failed = false;
  let screenshot = null;
  let cursor = null;
  try {
    const actionCursor = await action();
    if (actionCursor !== undefined) cursor = actionCursor;
  } catch (error) {
    failed = true;
  }
  try {
    screenshot = await capture();
  } catch (error) {
    failed = true;
    screenshot = null;
  }
  const wasBlocked = Boolean(blocked());
  const record = createProtocolRecord({
    runId,
    sequence,
    step,
    result: wasBlocked ? 'blocked' : (failed ? 'failed' : (terminal ? 'passed' : 'running')),
    screenshot,
    cursor,
    observedAt: now(),
  });
  write(record);
  if (wasBlocked) throw new NetworkPolicyViolation(`etapa ${step} bloqueada`);
  if (failed) throw new Error(`etapa ${step} falhou`);
  return record;
}

export async function moveCursor(page, x, y) {
  if (!Number.isFinite(x) || !Number.isFinite(y)) throw new Error('coordenada inválida');
  const cursor = { x, y };
  await page.mouse.move(cursor.x, cursor.y);
  await page.evaluate(({ x: cursorX, y: cursorY }) => {
    let overlay = document.getElementById('pregao-qa-readonly-cursor');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'pregao-qa-readonly-cursor';
      overlay.setAttribute('aria-hidden', 'true');
      document.documentElement.appendChild(overlay);
    }
    Object.assign(overlay.style, {
      position: 'fixed',
      left: `${cursorX}px`,
      top: `${cursorY}px`,
      width: '18px',
      height: '18px',
      border: '2px solid #FFE600',
      borderRadius: '50%',
      boxShadow: '0 0 0 3px rgba(7, 11, 20, .75)',
      transform: 'translate(-50%, -50%)',
      pointerEvents: 'none',
      zIndex: '2147483647',
    });
  }, cursor);
  return cursor;
}

export async function moveCursorToLocator(page, locator, unavailableMessage) {
  await locator.scrollIntoViewIfNeeded();
  const box = await locator.boundingBox();
  if (!box) throw new Error(unavailableMessage);
  return moveCursor(
    page,
    Math.round(box.x + box.width / 2),
    Math.round(box.y + box.height / 2),
  );
}

export function isIgnorableSandboxServiceWorkerError(error) {
  return error instanceof Error
    && error.name === 'SecurityError'
    && error.message === SANDBOX_SERVICE_WORKER_ERROR;
}

function parseCookie(rawCookie) {
  if (typeof rawCookie !== 'string' || rawCookie.length < 3) throw new Error('cookie de sessão ausente no env');
  const separator = rawCookie.indexOf('=');
  if (separator < 1) throw new Error('cookie de sessão inválido no env');
  const name = rawCookie.slice(0, separator);
  const value = rawCookie.slice(separator + 1);
  if (!/^[A-Za-z0-9_.-]+$/.test(name) || value.length < 1 || /[;\r\n]/.test(value)) {
    throw new Error('cookie de sessão inválido no env');
  }
  return { name, value };
}

export function parseRunnerEnv(env) {
  const baseUrl = parseHttpUrl(env.PREGAO_QA_BASE_URL, 'PREGAO_QA_BASE_URL');
  if (baseUrl.pathname !== '/' || baseUrl.search || baseUrl.hash) {
    throw new Error('PREGAO_QA_BASE_URL deve conter somente a origem');
  }
  assertUuid(env.PREGAO_QA_RUN_ID, 'PREGAO_QA_RUN_ID');
  if (!/^[1-9][0-9]*$/.test(env.PREGAO_QA_ACCOUNT_ID || '')) {
    throw new Error('PREGAO_QA_ACCOUNT_ID inválido');
  }
  if (typeof env.PREGAO_QA_OUTPUT_DIR !== 'string' || !path.isAbsolute(env.PREGAO_QA_OUTPUT_DIR)) {
    throw new Error('PREGAO_QA_OUTPUT_DIR absoluto é obrigatório');
  }
  const executablePath = env.PREGAO_QA_BROWSER_EXECUTABLE || '/usr/bin/google-chrome-stable';
  if (!path.isAbsolute(executablePath)) throw new Error('PREGAO_QA_BROWSER_EXECUTABLE absoluto é obrigatório');
  return {
    baseUrl,
    runId: env.PREGAO_QA_RUN_ID,
    accountId: Number(env.PREGAO_QA_ACCOUNT_ID),
    outputRoot: path.resolve(env.PREGAO_QA_OUTPUT_DIR),
    cookie: parseCookie(env.PREGAO_QA_SESSION_COOKIE),
    executablePath,
  };
}

async function captureLatest(page, outputRoot, step) {
  const target = latestScreenshotPath(outputRoot, step);
  await fs.mkdir(path.dirname(target.absolute), { recursive: true, mode: 0o700 });
  await page.screenshot({ path: target.absolute, type: 'png' });
  return target.protocol;
}

function emit(record) {
  process.stdout.write(`${JSON.stringify(record)}\n`);
}

export async function run(config) {
  const browser = await chromium.launch({ headless: true, executablePath: config.executablePath });
  let context;
  let sequence = 0;
  let cursor = null;
  const observations = {
    javascript: 0,
    http: 0,
    violations: 0,
  };

  try {
    context = await browser.newContext({
      serviceWorkers: 'block',
      acceptDownloads: false,
      userAgent: auditUserAgent(config.runId),
      viewport: { width: 1440, height: 1000 },
    });
    await context.addCookies([{
      name: config.cookie.name,
      value: config.cookie.value,
      url: config.baseUrl.origin,
      secure: config.baseUrl.protocol === 'https:',
      sameSite: 'Lax',
    }]);

    await context.route('**/*', async (route) => {
      const request = route.request();
      try {
        const decision = networkPolicyDecision(request.method(), request.url(), config.baseUrl);
        if (decision.kind === 'intercept') {
          await route.fulfill({
            status: 200,
            contentType: decision.contentType,
            body: decision.body,
            headers: { 'cache-control': 'no-store', 'x-pregao-qa-intercepted': '1' },
          });
          return;
        }
        const requestTarget = new URL(request.url());
        if (request.method() === 'GET' && requestTarget.pathname === '/api/pregao/stream') {
          await route.continue();
          return;
        }
        const response = await route.fetch({ maxRedirects: 0 });
        if (REDIRECT_STATUSES.has(response.status())) {
          assertReadonlyRedirect({
            status: response.status(),
            method: request.method(),
            fromUrl: request.url(),
            location: response.headers().location || '',
            baseUrl: config.baseUrl,
          });
        }
        await route.fulfill({ response });
      } catch (error) {
        if (error instanceof NetworkPolicyViolation) observations.violations += 1;
        else observations.http += 1;
        await route.abort('blockedbyclient');
      }
    });

    if (typeof context.routeWebSocket !== 'function') throw new Error('guarda WebSocket indisponível');
    await context.routeWebSocket(/.*/, async (socket) => {
      try {
        assertReadonlyWebSocket(socket.url(), config.baseUrl);
        socket.connectToServer();
      } catch (error) {
        observations.violations += 1;
        socket.close({ code: 1008, reason: 'same-origin obrigatório' });
      }
    });

    const page = await context.newPage();
    page.on('console', (message) => {
      if (message.type() === 'error') observations.javascript += 1;
    });
    page.on('pageerror', (error) => {
      if (!isIgnorableSandboxServiceWorkerError(error)) observations.javascript += 1;
    });
    page.on('response', (response) => {
      if (response.status() >= 400) observations.http += 1;
    });

    async function step(name, action) {
      sequence += 1;
      return executeProtocolStep({
        runId: config.runId,
        sequence,
        step: name,
        action,
        capture: () => captureLatest(page, config.outputRoot, name),
        write: emit,
        now: () => new Date().toISOString(),
        terminal: name === 'console_http',
        blocked: () => observations.violations !== 0,
      });
    }

    let snapshotResponse;
    let renderedAccountId;
    await step('dashboard', async () => {
      const snapshotWait = page.waitForResponse((response) => {
        const target = new URL(response.url());
        return target.origin === config.baseUrl.origin
          && target.pathname === '/api/pregao/snapshot'
          && response.request().method() === 'GET';
      }, { timeout: 15000 });
      await page.goto(new URL('/dashboard/pregao', config.baseUrl).toString(), {
        waitUntil: 'domcontentloaded',
        timeout: 20000,
      });
      const finalUrl = new URL(page.url());
      if (finalUrl.origin !== config.baseUrl.origin || finalUrl.pathname !== '/dashboard/pregao') {
        throw new Error('dashboard redirecionou');
      }
      await page.locator('#pregao-root[data-read-only="1"]').waitFor({ state: 'visible', timeout: 10000 });
      renderedAccountId = await page.locator('#pregao-root').getAttribute('data-account-id');
      snapshotResponse = await snapshotWait;
      const box = await page.locator('.pg-header').boundingBox();
      if (!box) throw new Error('cabeçalho indisponível');
      cursor = await moveCursor(page, Math.round(box.x + box.width / 2), Math.round(box.y + box.height / 2));
      return cursor;
    });

    await step('snapshot', async () => {
      if (!snapshotResponse || snapshotResponse.status() < 200 || snapshotResponse.status() >= 300) {
        throw new Error('snapshot HTTP inválido');
      }
      assertReadonlyRequest(
        snapshotResponse.request().method(),
        snapshotResponse.url(),
        config.baseUrl,
      );
      await snapshotResponse.finished();
      const snapshotBody = await snapshotResponse.json();
      assertAccountScope(renderedAccountId, snapshotBody, config.accountId);
      await page.locator('#semaText').waitFor({ state: 'visible', timeout: 10000 });
      const box = await page.locator('#semaText').boundingBox();
      if (!box) throw new Error('semáforo indisponível');
      cursor = await moveCursor(page, Math.round(box.x + box.width / 2), Math.round(box.y + box.height / 2));
      return cursor;
    });

    await step('realtime', async () => {
      await page.waitForFunction(() => {
        const connection = document.getElementById('conn');
        return Boolean(connection && (connection.classList.contains('ws') || connection.classList.contains('sse')));
      }, null, { timeout: 15000 });
      const box = await page.locator('#conn').boundingBox();
      if (!box) throw new Error('indicador realtime indisponível');
      cursor = await moveCursor(page, Math.round(box.x + box.width / 2), Math.round(box.y + box.height / 2));
      return cursor;
    });

    await step('event_explorer', async () => {
      const filterButton = page.locator('#eventsFilters button[type="submit"]');
      cursor = await moveCursorToLocator(page, filterButton, 'filtro de eventos indisponível');
      const eventsWait = page.waitForResponse((response) => {
        const target = new URL(response.url());
        return target.origin === config.baseUrl.origin
          && target.pathname === '/api/pregao/events'
          && response.request().method() === 'GET';
      }, { timeout: 15000 });
      await page.mouse.click(cursor.x, cursor.y);
      const response = await eventsWait;
      if (response.status() < 200 || response.status() >= 300) throw new Error('Event Explorer HTTP inválido');
      await page.locator('#eventsTotal').waitFor({ state: 'visible', timeout: 10000 });
      return cursor;
    });

    await step('console_http', async () => {
      if (observations.javascript !== 0 || observations.http !== 0) {
        throw new Error('erros de runtime observados');
      }
      if (observations.violations !== 0) {
        throw new NetworkPolicyViolation('execução incompleta por política de rede');
      }
      return cursor;
    });
  } finally {
    if (context) await context.close().catch(() => undefined);
    await browser.close().catch(() => undefined);
  }
}

async function main() {
  let config;
  try {
    config = parseRunnerEnv(process.env);
    await run(config);
  } catch (error) {
    process.stderr.write('[pregao-qa] execução read-only falhou de forma segura.\n');
    process.exitCode = 1;
  }
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
  await main();
}
