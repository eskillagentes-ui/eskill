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
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function parseHttpUrl(rawValue, label) {
  let target;
  try {
    target = new URL(rawValue);
  } catch (error) {
    throw new Error(`${label}: URL inválida`);
  }
  if (!['http:', 'https:'].includes(target.protocol)) throw new Error(`${label}: protocolo bloqueado`);
  if (target.username || target.password) throw new Error(`${label}: credenciais embutidas bloqueadas`);
  return target;
}

export function assertReadonlyRequest(method, rawUrl, baseUrl) {
  const target = parseHttpUrl(rawUrl, 'request');
  if (target.origin !== baseUrl.origin) throw new Error('request fora de same-origin');
  const normalizedMethod = String(method).toUpperCase();
  if (!READONLY_METHODS.has(normalizedMethod)) throw new Error(`método bloqueado: ${normalizedMethod}`);
  return target;
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
  runId, sequence, step, action, capture, write, now, terminal = false,
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
  const record = createProtocolRecord({
    runId,
    sequence,
    step,
    result: failed ? 'failed' : (terminal ? 'passed' : 'running'),
    screenshot,
    cursor,
    observedAt: now(),
  });
  write(record);
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
  if (typeof env.PREGAO_QA_OUTPUT_DIR !== 'string' || !path.isAbsolute(env.PREGAO_QA_OUTPUT_DIR)) {
    throw new Error('PREGAO_QA_OUTPUT_DIR absoluto é obrigatório');
  }
  const executablePath = env.PREGAO_QA_BROWSER_EXECUTABLE || '/usr/bin/google-chrome-stable';
  if (!path.isAbsolute(executablePath)) throw new Error('PREGAO_QA_BROWSER_EXECUTABLE absoluto é obrigatório');
  return {
    baseUrl,
    runId: env.PREGAO_QA_RUN_ID,
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
  const observations = { javascript: 0, http: 0, blocked: 0 };

  try {
    context = await browser.newContext({
      serviceWorkers: 'block',
      acceptDownloads: false,
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
      try {
        assertReadonlyRequest(route.request().method(), route.request().url(), config.baseUrl);
        await route.continue();
      } catch (error) {
        observations.blocked += 1;
        await route.abort('blockedbyclient');
      }
    });

    if (typeof context.routeWebSocket !== 'function') throw new Error('guarda WebSocket indisponível');
    await context.routeWebSocket(/.*/, async (socket) => {
      try {
        assertReadonlyWebSocket(socket.url(), config.baseUrl);
        socket.connectToServer();
      } catch (error) {
        observations.blocked += 1;
        socket.close({ code: 1008, reason: 'same-origin obrigatório' });
      }
    });

    const page = await context.newPage();
    page.on('console', (message) => {
      if (message.type() === 'error') observations.javascript += 1;
    });
    page.on('pageerror', () => { observations.javascript += 1; });
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
      });
    }

    let snapshotResponse;
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
      const box = await filterButton.boundingBox();
      if (!box) throw new Error('filtro de eventos indisponível');
      cursor = await moveCursor(page, Math.round(box.x + box.width / 2), Math.round(box.y + box.height / 2));
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
      if (observations.javascript !== 0 || observations.http !== 0 || observations.blocked !== 0) {
        throw new Error('erros de runtime observados');
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
