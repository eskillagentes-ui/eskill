import { test as base, type APIResponse } from '@playwright/test';

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

function installApiRequestGuard(request: unknown): () => void {
  const requestMethods = request as Record<GuardedApiMethod, ApiCall>;
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

  return () => {
    for (const [methodName, original] of originals) {
      requestMethods[methodName] = original;
    }
  };
}

export const test = base.extend<{ safeApiNetwork: void; safeNetwork: void }>({
  safeApiNetwork: [async ({ request }, use) => {
    const restore = installApiRequestGuard(request);
    await use();
    restore();
  }, { auto: true }],
  safeNetwork: [async ({ context }, use) => {
    const restoreContextRequest = installApiRequestGuard(context.request);
    await context.route('**/*', async (route) => {
      const request = route.request();
      const rawUrl = request.url();
      // Sem http(s) / documento / loopback local: continue.
      // route.fetch contra php -S deadlock com workers>1 (servidor single-thread).
      const isLoopbackHttp = /^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(?::\d+)?\//i.test(rawUrl);
      if (!/^https?:/i.test(rawUrl) || request.resourceType() === 'document' || isLoopbackHttp) {
        if (/^https?:/i.test(rawUrl)) {
          try {
            assertMutationRequestAllowed(request.method(), rawUrl);
          } catch (error) {
            await route.abort('blockedbyclient');
            throw error;
          }
        }
        await route.continue();
        return;
      }
      let response: APIResponse;
      try {
        assertMutationRequestAllowed(request.method(), rawUrl);
        response = await route.fetch({ maxRedirects: 0 });
        const status = response.status();
        const location = response.headers()['location'];
        if (status >= 300 && status < 400 && location) {
          const redirectUrl = new URL(location, rawUrl).toString();
          const redirectMethod = status === 307 || status === 308 ? request.method() : 'GET';
          assertMutationRequestAllowed(redirectMethod, redirectUrl);
        }
      } catch (error) {
        try {
          await route.abort('blockedbyclient');
        } catch (abortError) {
          if (!(abortError instanceof Error)
            || !abortError.message.includes('Route is already handled')) {
            throw abortError;
          }
        }
        throw error;
      }

      try {
        await route.fulfill({ response });
      } catch (fulfillError) {
        if (!(fulfillError instanceof Error)
          || !fulfillError.message.includes('Route is already handled')) {
          throw fulfillError;
        }
      }
    });

    await use();
    await context.unrouteAll({ behavior: 'ignoreErrors' });
    restoreContextRequest();
  }, { auto: true }],
});

export { expect } from '@playwright/test';
export type { Page } from '@playwright/test';
