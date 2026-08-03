import { test } from '@playwright/test';

/**
 * Produção não pode ser alvo do harness E2E mutante.
 *
 * Este sentinel não abre browser, não autentica e não faz requests. Ele existe
 * para que qualquer tentativa de executar o antigo spec falhe explicitamente,
 * inclusive quando alguém usa uma configuração Playwright alternativa.
 */
test('production validation is disabled', () => {
  throw new Error('production_e2e_disabled');
});
