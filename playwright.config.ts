import { defineConfig, devices } from '@playwright/test';

/**
 * Read environment variables from file.
 * https://github.com/motdotla/dotenv
 */
// import dotenv from 'dotenv';
// import path from 'path';
// dotenv.config({ path: path.resolve(__dirname, '.env') });

const PORT = process.env.PLAYWRIGHT_PORT || '8080';
const BASE_URL = process.env.PLAYWRIGHT_BASE_URL || `http://127.0.0.1:${PORT}`;

/**
 * production-validation.spec.ts roda contra https://eskill.com.br real e exige
 * credenciais dedicadas (PROD_EMAIL/PROD_PASSWORD). Fica de fora do pipeline
 * padrão (PR/full) e só é executado quando RUN_PROD_VALIDATION=1.
 */
const runProdValidation = process.env.RUN_PROD_VALIDATION === '1';

/**
 * Subconjunto seguro (npm run test:e2e:readonly): exclui os 6 specs com
 * POST/DELETE (31 pontos mutantes) — seo, render, exports, ai-center,
 * functional_ai_mcp, production-validation. Uso permitido apenas em ambiente local
 * ou staging isolado; nunca apontar esta suíte para produção.
 */
const e2eReadonly = process.env.E2E_READONLY === '1';
const mutationAllowed = process.env.E2E_ALLOW_MUTATION === 'true';
const mutatingSpecs = [
  '**/seo.spec.ts',
  '**/render.spec.ts',
  '**/exports.spec.ts',
  '**/ai-center.spec.ts',
  '**/functional_ai_mcp.spec.ts',
  '**/production-validation.spec.ts',
];

const testIgnore: string[] = [];
if (!runProdValidation) {
  testIgnore.push('**/production-validation.spec.ts');
}
if (e2eReadonly || (!mutationAllowed && !runProdValidation)) {
  for (const pattern of mutatingSpecs) {
    if (!testIgnore.includes(pattern)) {
      testIgnore.push(pattern);
    }
  }
}

/**
 * See https://playwright.dev/docs/test-configuration.
 */
export default defineConfig({
  testDir: './tests/e2e',
  testIgnore: testIgnore.length > 0 ? testIgnore : undefined,
  /* Run tests in files in parallel */
  fullyParallel: true,
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  /* Retry on CI only */
  retries: process.env.CI ? 2 : 0,
  /* Opt out of parallel tests on CI. */
  workers: process.env.CI ? 2 : undefined,
  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'html',
  /* Shared settings for all the projects below. See https://playwright.dev/docs/api/class-testoptions. */
  use: {
    /* Base URL to use in actions like `await page.goto('/login')`. */
    baseURL: BASE_URL,

    /* Collect trace when retrying the failed test. See https://playwright.dev/docs/trace-viewer */
    trace: 'on-first-retry',
    /* Evidências de falha para debug no CI. */
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  /* Configure projects for major browsers */
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },
    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
    },
    /** Tag @readonly — npm run test:e2e:readonly executa somente casos auditados. */
    {
      name: 'readonly',
      use: { ...devices['Desktop Chrome'] },
      grep: /@readonly/,
    },
  ],

  /* Sobe a aplicação PHP (router.php) antes dos testes, local e no CI. */
  webServer: process.env.PLAYWRIGHT_SKIP_WEBSERVER
    ? undefined
    : {
        command: `php -S 127.0.0.1:${PORT} router.php`,
        url: BASE_URL,
        reuseExistingServer: !process.env.CI,
        // PHP_CLI_SERVER_WORKERS (SO_REUSEPORT com múltiplos processos) foi
        // testado e causou "Timed out waiting from config.webServer" no
        // runner do GitHub Actions (funcionava localmente); mantendo o
        // servidor embutido em processo único, que já é suficiente para a
        // carga dos testes E2E e é o comportamento padrão/mais previsível.
        timeout: 60_000,
        env: {
          APP_ENV: process.env.APP_ENV || 'testing',
        },
      },
});
