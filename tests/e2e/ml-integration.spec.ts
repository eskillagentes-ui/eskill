import { test, expect } from './helpers/mutation-guard';

/**
 * Mercado Livre Integration — E2E
 *
 * Objetivo: validar que o fluxo OAuth está bem amarrado (sem 500) e que
 * rotas críticas respondem com redirecionamento esperado.
 *
 * Nota: o ambiente default de E2E roda com APP_ENV=testing e rede externa
 * desabilitada (ML_ALLOW_NETWORK=false). Por isso, aqui testamos apenas
 * redirecionamentos/contratos locais. Testes que dependem de login real são
 * condicionais (skip) por env.
 */

test.describe('Mercado Livre — OAuth & integração', () => {
  test('GET /auth/authorize deve exigir login (redirect para /login)', async ({ page }) => {
    await page.goto('/auth/authorize');
    await expect(page).toHaveURL(/\/login/);
  });

  test('GET /api/items deve retornar 401 sem autenticação', async ({ request }) => {
    const response = await request.get('/api/items?limit=1&allow_local_cache=true', { maxRedirects: 0 });
    expect(response.status()).toBe(401);
    const body = await response.json();
    expect(body).toMatchObject({ error: 'Unauthorized' });
  });

  test('GET /auth/callback sem code/state não deve dar 500 (redirect para /dashboard/accounts)', async ({ request }) => {
    const response = await request.get('/auth/callback', { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    const location = response.headers()['location'] ?? '';
    expect(['/dashboard', '/dashboard/accounts']).toContain(location);
  });

  test('GET /auth/callback com error não deve dar 500 (redirect para /dashboard/accounts)', async ({ request }) => {
    const response = await request.get('/auth/callback?error=access_denied&error_description=E2E', { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    const location = response.headers()['location'] ?? '';
    expect(['/dashboard', '/dashboard/accounts']).toContain(location);
  });

  test('GET /auth/authorize quando autenticado deve redirecionar para URL OAuth do ML (sem seguir redirect)', async ({ page }) => {
    const email = process.env.E2E_TEST_USER_EMAIL;
    const password = process.env.E2E_TEST_USER_PASSWORD;

    test.skip(!email || !password, 'Requer usuário de teste no banco (E2E_TEST_USER_EMAIL/E2E_TEST_USER_PASSWORD)');

    await page.goto('/login');
    await page.fill('input[name="email"], input[type="email"]', email!);
    await page.fill('input[name="password"], input[type="password"]', password!);
    await page.click('button[type="submit"], input[type="submit"]');

    // O app pode redirecionar para dashboard após login.
    await page.waitForURL('**/dashboard**', { timeout: 10000 });

    // Importante: não usar page.goto('/auth/authorize'), pois isso seguiria para domínio externo.
    const response = await page.request.get('/auth/authorize', { maxRedirects: 0 });
    expect(response.status()).toBe(302);

    const location = response.headers()['location'] ?? '';
    expect(location).toContain('https://auth.mercadolibre.com/authorization');
    expect(location).toContain('response_type=code');
    expect(location).toContain('client_id=');
    expect(location).toContain('redirect_uri=');
    expect(location).toContain('state=');
    expect(location).toContain('code_challenge=');
    expect(location).toContain('code_challenge_method=S256');
  });

  test('GET /api/items deve retornar 200 quando autenticado (offline-safe via allow_local_cache=true)', async ({ page }) => {
    const email = process.env.E2E_TEST_USER_EMAIL;
    const password = process.env.E2E_TEST_USER_PASSWORD;

    test.skip(!email || !password, 'Requer usuário de teste no banco (E2E_TEST_USER_EMAIL/E2E_TEST_USER_PASSWORD)');

    await page.goto('/login');
    await page.fill('input[name="email"], input[type="email"]', email!);
    await page.fill('input[name="password"], input[type="password"]', password!);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForURL('**/dashboard**', { timeout: 10000 });

    const response = await page.request.get('/api/items?limit=1&allow_local_cache=true', { maxRedirects: 0 });
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body).toHaveProperty('items');
    expect(Array.isArray(body.items)).toBeTruthy();
  });
});
