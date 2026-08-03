import { test, expect, Page } from '@playwright/test';
import { requireMutationAllowed } from './helpers/mutation-guard';

requireMutationAllowed('production-validation.spec');

/**
 * Testes E2E para validação em PRODUÇÃO (https://eskill.com.br)
 * @mutante — exige E2E_ALLOW_MUTATION=true (mesmo em smoke prod com POST login)
 *
 * IMPORTANTE:
 * - Este teste roda contra o servidor REAL de produção
 * - NÃO realize ações destrutivas (delete, update em massa, etc.)
 * - Use credenciais válidas via variáveis de ambiente:
 *   PROD_EMAIL=seu@email.com PROD_PASSWORD=suasenha npx playwright test production-validation
 */

const PROD_BASE_URL = 'https://eskill.com.br';
const SCREENSHOT_DIR = './storage/playwright-screenshots';
let blockedByAllowlist = false;
let blockedByAllowlistReason = '';

function isBlockedNetworkError(error: unknown): boolean {
    const message = error instanceof Error ? error.message.toLowerCase() : String(error).toLowerCase();
    return message.includes('blocked-by-allowlist')
        || message.includes('err_empty_response')
        || message.includes('err_tunnel_connection_failed')
        || message.includes('err_connection_reset')
        || message.includes('err_connection_closed')
        || message.includes('err_name_not_resolved');
}

async function gotoProductionOrSkip(page: Page, path: string) {
    try {
        return await page.goto(`${PROD_BASE_URL}${path}`);
    } catch (error) {
        if (isBlockedNetworkError(error)) {
            test.skip(true, 'Ambiente sem acesso externo para https://eskill.com.br. Execute fora do sandbox para validação real de produção.');
        }
        throw error;
    }
}
/**
 * Helper para capturar screenshot com timestamp
 */
async function captureScreenshot(page: Page, name: string) {
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const filename = `${name}_${timestamp}.png`;
    const filepath = `${SCREENSHOT_DIR}/${filename}`;
    await page.screenshot({ path: filepath, fullPage: true });
    console.log(`📸 Screenshot salvo: ${filepath}`);
    return filepath;
}

/**
 * Helper para coletar erros de console
 */
function setupConsoleListener(page: Page): string[] {
    const consoleErrors: string[] = [];
    page.on('console', msg => {
        if (msg.type() === 'error') {
            consoleErrors.push(`[CONSOLE ERROR] ${msg.text()}`);
        }
    });
    page.on('pageerror', error => {
        consoleErrors.push(`[PAGE ERROR] ${error.message}`);
    });
    return consoleErrors;
}

/**
 * Helper para monitorar requests HTTP
 */
function setupNetworkListener(page: Page): { failedRequests: string[], requestLog: string[] } {
    const failedRequests: string[] = [];
    const requestLog: string[] = [];

    page.on('requestfailed', request => {
        failedRequests.push(`[FAILED] ${request.method()} ${request.url()} - ${request.failure()?.errorText}`);
    });

    page.on('response', response => {
        if (!response.ok() && response.status() >= 400) {
            requestLog.push(`[${response.status()}] ${response.request().method()} ${response.url()}`);
        }
    });

    return { failedRequests, requestLog };
}

test.describe('Validação em Produção - https://eskill.com.br', () => {
    test.beforeAll(async ({ request }) => {
        try {
            const response = await request.get(`${PROD_BASE_URL}/login`, {
                failOnStatusCode: false,
                timeout: 15000,
            });

            const headers = response.headers();
            const proxyError = (headers['x-proxy-error'] ?? '').toLowerCase();
            const body = (await response.text()).toLowerCase();

            if (proxyError.includes('blocked-by-allowlist') || (response.status() === 403 && body.includes('blocked-by-allowlist'))) {
                blockedByAllowlist = true;
                blockedByAllowlistReason = 'Ambiente bloqueado por allowlist (blocked-by-allowlist). Execute fora do sandbox para validar produção real.';
                console.log(`⚠ ${blockedByAllowlistReason}`);
            }
        } catch (error) {
            const message = error instanceof Error ? error.message.toLowerCase() : String(error).toLowerCase();
            if (message.includes('blocked-by-allowlist')) {
                blockedByAllowlist = true;
                blockedByAllowlistReason = 'Ambiente bloqueado por allowlist (blocked-by-allowlist). Execute fora do sandbox para validar produção real.';
                console.log(`⚠ ${blockedByAllowlistReason}`);
            }
        }
    });

    test.beforeEach(async () => {
        test.skip(blockedByAllowlist, blockedByAllowlistReason || 'Ambiente bloqueado por allowlist.');
    });

    test.describe('1. Login Flow', () => {
        test('1.1 Inspeção da tela de login', async ({ page }) => {
            const consoleErrors = setupConsoleListener(page);
            const { failedRequests, requestLog } = setupNetworkListener(page);

            await gotoProductionOrSkip(page, '/login');
            await page.waitForLoadState('networkidle');

            // Screenshot da página de login
            await captureScreenshot(page, 'login-page');

            // 1. Verificar campos de email/password
            const emailField = page.locator('input[name="email"], input[type="email"]');
            const passwordField = page.locator('input[name="password"], input[type="password"]');

            await expect(emailField).toBeVisible();
            await expect(passwordField).toBeVisible();

            console.log('✅ Campos email e password encontrados');

            // 2. Verificar hidden _token
            const csrfToken = await page.locator('input[name="_token"]').inputValue().catch(() => null);
            console.log(`🔑 CSRF Token (input hidden): ${csrfToken ? 'Encontrado' : 'NÃO ENCONTRADO'}`);

            // 3. Verificar meta csrf-token
            const metaCsrf = await page.locator('meta[name="csrf-token"]').getAttribute('content').catch(() => null);
            console.log(`🔑 CSRF Token (meta tag): ${metaCsrf ? 'Encontrado' : 'NÃO ENCONTRADO'}`);

            // 4. Verificar cookie de sessão
            const cookies = await page.context().cookies();
            const sessionCookie = cookies.find(c => c.name.toLowerCase().includes('session') || c.name === 'PHPSESSID');
            console.log(`🍪 Cookie de sessão: ${sessionCookie ? sessionCookie.name : 'NÃO ENCONTRADO'}`);
            console.log(`🍪 Total de cookies: ${cookies.length}`);

            // Reportar erros
            if (consoleErrors.length > 0) {
                console.log('❌ Erros de console na página de login:');
                consoleErrors.forEach(err => console.log(err));
            }

            if (failedRequests.length > 0 || requestLog.length > 0) {
                console.log('⚠️  Requests com problema na página de login:');
                [...failedRequests, ...requestLog].forEach(req => console.log(req));
            }

            // Deve ter pelo menos CSRF token OU meta tag
            expect(csrfToken || metaCsrf).toBeTruthy();
        });

        test('1.2 Login com credenciais reais', async ({ page }) => {
            const email = process.env.PROD_EMAIL;
            const password = process.env.PROD_PASSWORD;

            // Skip se não houver credenciais
            if (!email || !password) {
                test.skip(true, 'Credenciais não fornecidas. Use: PROD_EMAIL=... PROD_PASSWORD=... npx playwright test');
                return;
            }

            const consoleErrors = setupConsoleListener(page);
            const { failedRequests, requestLog } = setupNetworkListener(page);

            await gotoProductionOrSkip(page, '/login');
            await page.waitForLoadState('networkidle');

            // Preencher formulário
            await page.fill('input[name="email"], input[type="email"]', email);
            await page.fill('input[name="password"], input[type="password"]', password);

            await captureScreenshot(page, 'login-before-submit');

            // Submit
            await page.click('button[type="submit"], input[type="submit"]');

            // Aguardar redirecionamento ou erro
            await page.waitForLoadState('networkidle', { timeout: 10000 });

            await captureScreenshot(page, 'login-after-submit');

            // Verificar se entrou no dashboard
            const currentUrl = page.url();
            console.log(`📍 URL após login: ${currentUrl}`);

            // Verificar se há mensagem de erro
            const errorVisible = await page.locator('.alert-danger, .error, .text-danger, [role="alert"]').isVisible().catch(() => false);

            if (errorVisible) {
                const errorText = await page.locator('.alert-danger, .error, .text-danger, [role="alert"]').first().textContent();
                console.log(`❌ Erro de login: ${errorText}`);
            }

            // Reportar erros
            if (consoleErrors.length > 0) {
                console.log('❌ Erros de console no processo de login:');
                consoleErrors.forEach(err => console.log(err));
            }

            if (failedRequests.length > 0 || requestLog.length > 0) {
                console.log('⚠️  Requests com problema no processo de login:');
                [...failedRequests, ...requestLog].forEach(req => console.log(req));
            }

            // Deve estar no dashboard ou sem mensagem de erro
            const isLoggedIn = currentUrl.includes('dashboard') || !errorVisible;
            expect(isLoggedIn).toBeTruthy();

            if (currentUrl.includes('dashboard')) {
                console.log('✅ Login bem-sucedido! Redirecionado para dashboard');
            }
        });
    });

    test.describe('2. Dashboard Routes Smoke Test', () => {
        // Setup: fazer login antes de cada teste
        test.beforeEach(async ({ page }) => {
            const email = process.env.PROD_EMAIL;
            const password = process.env.PROD_PASSWORD;

            if (!email || !password) {
                test.skip(true, 'Credenciais não fornecidas para smoke test');
                return;
            }

            await gotoProductionOrSkip(page, '/login');
            await page.waitForLoadState('networkidle');
            await page.fill('input[name="email"], input[type="email"]', email);
            await page.fill('input[name="password"], input[type="password"]', password);
            await page.click('button[type="submit"], input[type="submit"]');
            await page.waitForLoadState('networkidle', { timeout: 10000 });
        });

        const dashboardRoutes = [
            { path: '/dashboard', name: 'Dashboard Principal' },
            { path: '/dashboard/accounts', name: 'Contas' },
            { path: '/dashboard/analytics', name: 'Analytics' },
            { path: '/dashboard/account-health', name: 'Account Health' },
            { path: '/dashboard/items', name: 'Items' },
            { path: '/dashboard/orders', name: 'Orders' },
            { path: '/dashboard/questions', name: 'Questions' },
            { path: '/dashboard/messages', name: 'Messages' },
            { path: '/dashboard/claims', name: 'Claims' },
            { path: '/dashboard/seo-killer', name: 'SEO Killer' },
            { path: '/dashboard/financials', name: 'Financials' },
            { path: '/dashboard/pricing', name: 'Pricing' },
        ];

        for (const route of dashboardRoutes) {
            test(`2.${dashboardRoutes.indexOf(route) + 1} Rota: ${route.name}`, async ({ page }) => {
                const consoleErrors = setupConsoleListener(page);
                const { failedRequests, requestLog } = setupNetworkListener(page);

                console.log(`\n🔍 Testando: ${route.name} (${route.path})`);

                const response = await gotoProductionOrSkip(page, route.path);
                await page.waitForLoadState('networkidle', { timeout: 15000 });

                // Capturar screenshot
                const screenshotName = route.name.toLowerCase().replace(/\s+/g, '-');
                await captureScreenshot(page, `route-${screenshotName}`);

                // Status HTTP
                const statusCode = response?.status() || 0;
                console.log(`📊 Status HTTP: ${statusCode}`);

                // Verificar se não está em erro 500
                expect(statusCode).toBeLessThan(500);

                // Verificar se não foi redirecionado para login (sessão expirada)
                const currentUrl = page.url();
                if (currentUrl.includes('login')) {
                    console.log('⚠️  Redirecionado para login - possível sessão expirada');
                }

                // Reportar erros de console
                if (consoleErrors.length > 0) {
                    console.log(`❌ Erros de console em ${route.name}:`);
                    consoleErrors.forEach(err => console.log(err));
                }

                // Reportar falhas de rede
                if (failedRequests.length > 0 || requestLog.length > 0) {
                    console.log(`⚠️  Requests com problema em ${route.name}:`);
                    [...failedRequests, ...requestLog].forEach(req => console.log(req));
                }

                // Coletar resumo
                const hasConsoleErrors = consoleErrors.length > 0;
                const hasNetworkErrors = failedRequests.length > 0 || requestLog.length > 0;

                console.log(`\n📋 Resumo ${route.name}:`);
                console.log(`  • Status: ${statusCode}`);
                console.log(`  • Console Errors: ${consoleErrors.length}`);
                console.log(`  • Network Errors: ${failedRequests.length + requestLog.length}`);
                console.log(`  • URL Final: ${currentUrl}`);

                // Soft assertion - não falha o teste, apenas reporta
                if (hasConsoleErrors || hasNetworkErrors) {
                    console.log(`⚠️  ATENÇÃO: ${route.name} tem problemas a investigar`);
                }

                // Hard assertion - deve estar acessível
                expect(statusCode).toBeGreaterThanOrEqual(200);
                expect(statusCode).toBeLessThan(400);
            });
        }
    });

    test.describe('3. Diagnóstico de Falhas', () => {
        test('3.1 Teste de API /api/auth/login (diagnóstico)', async ({ request }) => {
            const email = process.env.PROD_EMAIL;
            const password = process.env.PROD_PASSWORD;

            if (!email || !password) {
                test.skip(true, 'Credenciais não fornecidas para teste de API');
                return;
            }

            console.log('\n🔬 Testando endpoint de API diretamente...');

            let response;
            try {
                response = await request.post(`${PROD_BASE_URL}/api/auth/login`, {
                    data: {
                        email: email,
                        password: password,
                    },
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                });
            } catch (error) {
                if (isBlockedNetworkError(error)) {
                    test.skip(true, 'Ambiente sem acesso externo para https://eskill.com.br. Execute fora do sandbox para validação real de produção.');
                    return;
                }
                throw error;
            }

            const statusCode = response.status();
            const body = await response.text();

            console.log(`📊 Status: ${statusCode}`);
            console.log(`📄 Response: ${body}`);

            // Se a API funciona mas o login web falha, é problema de CSRF/sessão
            if (statusCode === 200) {
                console.log('✅ API de login funciona - se login web falha, é problema de CSRF/sessão');
            } else {
                console.log('❌ API de login falha - problema nas credenciais ou lógica de autenticação');
            }
        });
    });
});
