import fs from 'fs';
import path from 'path';
import {
  assertMutationRequestAllowed,
  assertSafePlaywrightTarget,
  assertSafeRequestTarget,
  expect,
  requireMutationAllowed,
  test,
} from './helpers/mutation-guard';

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

function listSpecFiles(directory: string): string[] {
  const files: string[] = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...listSpecFiles(target));
    } else if (/\.spec\.(?:ts|js)$/.test(entry.name)) {
      files.push(target);
    }
  }

  return files;
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
    expect(() => assertSafePlaywrightTarget('https://eskill.com.br')).toThrow(/E2E não permitido/);
    expect(() => assertSafePlaywrightTarget('https://www.eskill.com.br')).toThrow(/E2E não permitido/);
    expect(() => assertSafePlaywrightTarget('https://example.com')).toThrow(/E2E não permitido/);
  });

  test('@readonly bloqueia host efetivo de request e redirect', () => {
    expect(() => assertSafeRequestTarget('http://127.0.0.1:8080/login')).not.toThrow();
    expect(() => assertSafeRequestTarget('http://staging.eskill.com.br/login')).not.toThrow();
    expect(() => assertSafeRequestTarget('https://fonts.googleapis.com/css2')).not.toThrow();
    expect(() => assertSafePlaywrightTarget('https://fonts.googleapis.com')).toThrow(/E2E não permitido/);
    expect(() => assertSafeRequestTarget('https://eskill.com.br/login')).toThrow(/request E2E/);
    expect(() => assertSafeRequestTarget('https://example.com/redirect')).toThrow(/request E2E/);
  });

  test('@readonly bloqueia qualquer método mutante sem flag e fora do staging', () => {
    delete process.env.E2E_ALLOW_MUTATION;
    process.env.PLAYWRIGHT_BASE_URL = 'http://staging.eskill.com.br';
    expect(() => assertMutationRequestAllowed('POST', 'http://staging.eskill.com.br/login'))
      .toThrow(/mutações bloqueadas/);

    process.env.E2E_ALLOW_MUTATION = 'true';
    process.env.PLAYWRIGHT_BASE_URL = 'http://127.0.0.1:8080';
    expect(() => assertMutationRequestAllowed('POST', 'http://127.0.0.1:8080/login'))
      .toThrow(/staging/);

    process.env.PLAYWRIGHT_BASE_URL = 'http://staging.eskill.com.br';
    expect(() => assertMutationRequestAllowed('POST', 'http://staging.eskill.com.br/login'))
      .not.toThrow();
    expect(() => assertMutationRequestAllowed('GET', 'http://staging.eskill.com.br/login'))
      .not.toThrow();
  });

  test('@readonly fixture request bloqueia host absoluto e POST antes da rede', async ({ request }) => {
    delete process.env.E2E_ALLOW_MUTATION;
    process.env.PLAYWRIGHT_BASE_URL = 'http://127.0.0.1:8080';

    await expect(request.get('https://eskill.com.br/login')).rejects.toThrow(/request E2E/);
    await expect(request.post('/login', { data: { email: 'blocked@example.invalid' } }))
      .rejects.toThrow(/mutações bloqueadas/);
  });

  test('@readonly page.request usa o mesmo guard de host e mutação', async ({ page }) => {
    delete process.env.E2E_ALLOW_MUTATION;
    process.env.PLAYWRIGHT_BASE_URL = 'http://127.0.0.1:8080';

    await expect(page.request.get('https://eskill.com.br/login')).rejects.toThrow(/request E2E/);
    await expect(page.request.post('/login')).rejects.toThrow(/mutações bloqueadas/);
  });

  test('@readonly todos os specs usam a fixture central', () => {
    const e2eDir = path.resolve(__dirname);
    const specs = listSpecFiles(e2eDir);
    const directImport = 'from ' + "'@playwright/test'";
    const directRequire = 'require(' + "'@playwright/test'" + ')';

    for (const file of specs) {
      const source = fs.readFileSync(file, 'utf8');
      expect(source, file).toContain('helpers/mutation-guard');
      expect(source, file).not.toContain(directImport);
      expect(source, file).not.toContain(directRequire);
    }
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
