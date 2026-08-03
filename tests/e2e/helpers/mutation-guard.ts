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
        '(somente staging). Use npm run test:e2e:readonly em produção.'
    );
  }
}

export const test = base;
export { expect } from '@playwright/test';
