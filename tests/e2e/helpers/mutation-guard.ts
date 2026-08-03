import { test as base } from '@playwright/test';

const SAFE_E2E_HOSTS = new Set([
  '127.0.0.1',
  'localhost',
  '[::1]',
  'staging.eskill.com.br',
]);

function parseHttpTarget(rawBaseUrl: string, label: string): URL {
  let target: URL;
  try {
    target = new URL(rawBaseUrl);
  } catch {
    throw new Error(`[${label}] PLAYWRIGHT_BASE_URL inválida.`);
  }

  if (!['http:', 'https:'].includes(target.protocol)) {
    throw new Error(`[${label}] protocolo inválido.`);
  }

  if (target.username !== '' || target.password !== '') {
    throw new Error(`[${label}] credenciais embutidas não são permitidas.`);
  }

  return target;
}

/** Impede que qualquer projeto Playwright use produção ou host remoto arbitrário. */
export function assertSafePlaywrightTarget(rawBaseUrl: string): void {
  const target = parseHttpTarget(rawBaseUrl, 'playwright');
  if (!SAFE_E2E_HOSTS.has(target.hostname.toLowerCase())) {
    throw new Error('[playwright] destino E2E não permitido; use loopback ou staging.eskill.com.br.');
  }
}

/**
 * Specs mutantes (POST/DELETE) exigem E2E_ALLOW_MUTATION=true.
 * Default false — falha rápido fora de staging.
 */
export function requireMutationAllowed(suiteName: string): void {
  const allowed = process.env.E2E_ALLOW_MUTATION === 'true';
  if (!allowed) {
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

  assertSafePlaywrightTarget(rawBaseUrl);
  const target = parseHttpTarget(rawBaseUrl, suiteName);

  if (target.hostname.toLowerCase() !== 'staging.eskill.com.br') {
    throw new Error(`[${suiteName}] mutações bloqueadas: destino deve ser staging.eskill.com.br.`);
  }
}

export const test = base;
export { expect } from '@playwright/test';
