import { expect, test } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { assertSafePlaywrightTarget, requireMutationAllowed } from './helpers/mutation-guard';

const originalAllowMutation = process.env.E2E_ALLOW_MUTATION;
const originalBaseUrl = process.env.PLAYWRIGHT_BASE_URL;

function restoreEnv(): void {
  if (originalAllowMutation === undefined) {
    delete process.env.E2E_ALLOW_MUTATION;
  } else {
    process.env.E2E_ALLOW_MUTATION = originalAllowMutation;
  }

  if (originalBaseUrl === undefined) {
    delete process.env.PLAYWRIGHT_BASE_URL;
  } else {
    process.env.PLAYWRIGHT_BASE_URL = originalBaseUrl;
  }
}

test.describe('Mutation guard contract @readonly', () => {
  test.describe.configure({ mode: 'serial' });

  test.afterEach(() => {
    restoreEnv();
  });

  test('@readonly bloqueia quando a flag não está habilitada', () => {
    delete process.env.E2E_ALLOW_MUTATION;
    process.env.PLAYWRIGHT_BASE_URL = 'http://staging.eskill.com.br';

    expect(() => requireMutationAllowed('guard-test')).toThrow(/mutações bloqueadas/);
  });

  test('@readonly bloqueia target ausente mesmo com flag habilitada', () => {
    process.env.E2E_ALLOW_MUTATION = 'true';
    delete process.env.PLAYWRIGHT_BASE_URL;

    expect(() => requireMutationAllowed('guard-test')).toThrow(/PLAYWRIGHT_BASE_URL/);
  });

  test('@readonly bloqueia produção mesmo com flag habilitada', () => {
    process.env.E2E_ALLOW_MUTATION = 'true';
    process.env.PLAYWRIGHT_BASE_URL = 'https://eskill.com.br';

    expect(() => requireMutationAllowed('guard-test')).toThrow(/staging/);
  });

  test('@readonly bloqueia URL com credenciais embutidas', () => {
    process.env.E2E_ALLOW_MUTATION = 'true';
    process.env.PLAYWRIGHT_BASE_URL = 'http://user:password@staging.eskill.com.br';

    expect(() => requireMutationAllowed('guard-test')).toThrow(/credenciais/);
  });

  test('@readonly permite somente o host explícito de staging', () => {
    process.env.E2E_ALLOW_MUTATION = 'true';
    process.env.PLAYWRIGHT_BASE_URL = 'http://staging.eskill.com.br';

    expect(() => requireMutationAllowed('guard-test')).not.toThrow();
  });

  test('@readonly target global permite apenas loopback ou staging', () => {
    expect(() => assertSafePlaywrightTarget('http://127.0.0.1:8080')).not.toThrow();
    expect(() => assertSafePlaywrightTarget('http://localhost:8080')).not.toThrow();
    expect(() => assertSafePlaywrightTarget('http://staging.eskill.com.br')).not.toThrow();
    expect(() => assertSafePlaywrightTarget('https://eskill.com.br')).toThrow(/destino E2E/);
    expect(() => assertSafePlaywrightTarget('https://www.eskill.com.br')).toThrow(/destino E2E/);
    expect(() => assertSafePlaywrightTarget('https://example.com')).toThrow(/destino E2E/);
  });

  test('@readonly script npm usa o projeto readonly, não chromium', () => {
    const packagePath = path.resolve(__dirname, '../../package.json');
    const packageJson = JSON.parse(fs.readFileSync(packagePath, 'utf8')) as {
      scripts?: Record<string, string>;
    };
    const command = packageJson.scripts?.['test:e2e:readonly'] ?? '';

    expect(command).toContain('--project=readonly');
    expect(command).not.toContain('--project=chromium');
  });

  test('@readonly package não expõe smoke mutante de produção', () => {
    const packagePath = path.resolve(__dirname, '../../package.json');
    const packageJson = JSON.parse(fs.readFileSync(packagePath, 'utf8')) as {
      scripts?: Record<string, string>;
    };

    expect(packageJson.scripts).not.toHaveProperty('smoke:prod');
    expect(packageJson.scripts).not.toHaveProperty('smoke:prod:ui');
  });
});
