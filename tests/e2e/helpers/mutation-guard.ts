import { test as base } from '@playwright/test';

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

  let target: URL;
  try {
    target = new URL(rawBaseUrl);
  } catch {
    throw new Error(`[${suiteName}] mutações bloqueadas: PLAYWRIGHT_BASE_URL inválida.`);
  }

  if (!['http:', 'https:'].includes(target.protocol)) {
    throw new Error(`[${suiteName}] mutações bloqueadas: protocolo inválido.`);
  }

  if (target.username !== '' || target.password !== '') {
    throw new Error(`[${suiteName}] mutações bloqueadas: credenciais embutidas não são permitidas.`);
  }

  if (target.hostname.toLowerCase() !== 'staging.eskill.com.br') {
    throw new Error(`[${suiteName}] mutações bloqueadas: destino deve ser staging.eskill.com.br.`);
  }
}

export const test = base;
export { expect } from '@playwright/test';
