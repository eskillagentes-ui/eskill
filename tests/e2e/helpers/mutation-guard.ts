import { test as base } from '@playwright/test';

const SAFE_BASE_HOSTS = new Set([
  '127.0.0.1',
  'localhost',
  '[::1]',
  'staging.eskill.com.br',
]);
const SAFE_REQUEST_HOSTS = new Set([
  ...SAFE_BASE_HOSTS,
  'cdn.jsdelivr.net',
  'fonts.googleapis.com',
  'fonts.gstatic.com',
]);
const READONLY_HTTP_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);
const GUARDED_API_METHODS = ['delete', 'fetch', 'get', 'head', 'patch', 'post', 'put'] as const;
type GuardedApiMethod = typeof GUARDED_API_METHODS[number];
type ApiOptions = Record<string, unknown> & { method?: string; maxRedirects?: number };
type ApiCall = (url: string, options?: ApiOptions) => Promise<unknown>;

function parseHttpTarget(rawUrl: string, label: string): URL {
  let target: URL;
  try {
    target = new URL(rawUrl);
  } catch {
    throw new Error(`[${label}] URL inválida.`);
  }

  if (!['http:', 'https:'].includes(target.protocol)) {
    throw new Error(`[${label}] protocolo inválido.`);
  }

  if (target.username !== '' || target.password !== '') {
    throw new Error(`[${label}] credenciais embutidas não são permitidas.`);
  }

  return target;
}

function assertSafeHost(target: URL, label: string, allowedHosts: ReadonlySet<string>): void {
  if (!allowedHosts.has(target.hostname.toLowerCase())) {
    throw new Error(
      `[${label}] host E2E não permitido: ${target.hostname.toLowerCase()}; ` +
        'use loopback ou staging.eskill.com.br.'
    );
  }
}

/** Impede que qualquer projeto Playwright use produção ou host remoto arbitrário. */
export function assertSafePlaywrightTarget(rawBaseUrl: string): void {
  const target = parseHttpTarget(rawBaseUrl, 'playwright');
  assertSafeHost(target, 'playwright', SAFE_BASE_HOSTS);
}

/** Valida o URL efetivo de cada request, inclusive redirects e URLs absolutas. */
export function assertSafeRequestTarget(rawUrl: string): void {
  const target = parseHttpTarget(rawUrl, 'request E2E');
  assertSafeHost(target, 'request E2E', SAFE_REQUEST_HOSTS);
}

/** Qualquer request HTTP mutante exige flag e destino efetivo de staging. */
export function assertMutationRequestAllowed(method: string, rawUrl: string): void {
  assertSafeRequestTarget(rawUrl);
  if (READONLY_HTTP_METHODS.has(method.toUpperCase())) {
    return;
  }

  requireMutationAllowed(`request ${method.toUpperCase()}`);
  const target = parseHttpTarget(rawUrl, 'request mutante');
  if (target.hostname.toLowerCase() !== 'staging.eskill.com.br') {
    throw new Error('[request mutante] mutações bloqueadas: destino deve ser staging.eskill.com.br.');
  }
}

/**
 * Specs mutantes exigem E2E_ALLOW_MUTATION=true e base explícita de staging.
 * Default false — falha rápido fora de staging.
 */
export function requireMutationAllowed(suiteName: string): void {
  if (process.env.E2E_ALLOW_MUTATION !== 'true') {
    throw new Error(
      `[${suiteName}] mutações bloqueadas: defina E2E_ALLOW_MUTATION=true ` +
        '(somente no staging explicitamente permitido).'
    );
  }

  const rawBaseUrl = process.env.PLAYWRIGHT_BASE_URL;
  if (!rawBaseUrl) {
    throw new Error(
      `[${suiteName}] mutações bloqueadas: PLAYWRIGHT_BASE_URL explícita é obrigatória.`
    );
  }

  const target = parseHttpTarget(rawBaseUrl, suiteName);
  assertSafeHost(target, suiteName, SAFE_BASE_HOSTS);
  if (target.hostname.toLowerCase() !== 'staging.eskill.com.br') {
    throw new Error(`[${suiteName}] mutações bloqueadas: destino deve ser staging.eskill.com.br.`);
  }
}

export const test = base.extend<{ safeApiNetwork: void; safeNetwork: void }>({
  safeApiNetwork: [async ({ request }, use) => {
    const requestMethods = request as unknown as Record<GuardedApiMethod, ApiCall>;
    const originals = new Map<GuardedApiMethod, ApiCall>();
    const baseUrl = process.env.PLAYWRIGHT_BASE_URL
      ?? `http://127.0.0.1:${process.env.PLAYWRIGHT_PORT ?? '8080'}`;

    for (const methodName of GUARDED_API_METHODS) {
      const original = requestMethods[methodName].bind(request);
      originals.set(methodName, original);
      requestMethods[methodName] = async (url, options = {}) => {
        const effectiveUrl = new URL(url, baseUrl).toString();
        const method = methodName === 'fetch'
          ? String(options.method ?? 'GET')
          : methodName.toUpperCase();
        assertMutationRequestAllowed(method, effectiveUrl);

        return original(url, { ...options, maxRedirects: 0 });
      };
    }

    await use();
    for (const [methodName, original] of originals) {
      requestMethods[methodName] = original;
    }
  }, { auto: true }],
  safeNetwork: [async ({ context }, use) => {
    await context.route('**/*', async (route) => {
      const request = route.request();
      try {
        assertMutationRequestAllowed(request.method(), request.url());
        await route.continue();
      } catch (error) {
        await route.abort('blockedbyclient');
        throw error;
      }
    });

    await use();
    await context.unroute('**/*');
  }, { auto: true }],
});

export { expect } from '@playwright/test';
export type { Page } from '@playwright/test';
