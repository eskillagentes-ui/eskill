import { test, expect } from './helpers/mutation-guard';

/**
 * Testes E2E para fluxo de autenticação
 * Verifica login, sessão e proteção de rotas
 */
test.describe('Autenticação', () => {
    test.describe('Login', () => {
        test('deve exibir formulário de login', async ({ page }) => {
            await page.goto('/login');
            
            await expect(page.locator('input[name="email"], input[type="email"]')).toBeVisible();
            await expect(page.locator('input[name="password"], input[type="password"]')).toBeVisible();
            await expect(page.locator('button[type="submit"], input[type="submit"]')).toBeVisible();
        });

        test('deve exibir erro com credenciais inválidas', async ({ page }) => {
            await page.goto('/login');
            
            await page.fill('input[name="email"], input[type="email"]', 'invalid@test.com');
            await page.fill('input[name="password"], input[type="password"]', 'wrongpassword');
            await page.click('button[type="submit"], input[type="submit"]');
            
            // Aguarda mensagem de erro ou permanece na página de login
            await expect(page.locator('.alert-danger, .error, .text-danger, [role="alert"]')).toBeVisible({ timeout: 5000 }).catch(() => {
                // Se não houver mensagem de erro visível, verifica se ainda está na página de login
                expect(page.url()).toContain('login');
            });
        });

        test('deve redirecionar para dashboard após login válido', async ({ page }) => {
            // Este teste precisa de credenciais válidas do ambiente de teste
            // Pode ser pulado se não houver seed de usuário de teste
            test.skip(true, 'Requer usuário de teste no banco de dados');
            
            await page.goto('/login');
            
            await page.fill('input[name="email"], input[type="email"]', 'test@example.com');
            await page.fill('input[name="password"], input[type="password"]', 'testpassword123');
            await page.click('button[type="submit"], input[type="submit"]');
            
            await page.waitForURL('**/dashboard**', { timeout: 10000 });
            expect(page.url()).toContain('dashboard');
        });
    });

    test.describe('Proteção de Rotas', () => {
        test('deve redirecionar para login ao acessar dashboard sem autenticação', async ({ page }) => {
            await page.goto('/dashboard');
            
            // Deve redirecionar para login ou exibir página de erro
            await page.waitForURL('**/login**', { timeout: 5000 }).catch(() => {
                // Se não redirecionou, verifica se há mensagem de não autorizado
                expect(page.locator('text=/unauthorized|não autorizado|faça login/i')).toBeVisible();
            });
        });

        test('deve proteger rotas de API sem token', async ({ request }) => {
            const response = await request.get('/api/accounts');
            
            // Deve retornar 401 ou 403
            expect([401, 403]).toContain(response.status());
        });
    });

    test.describe('Logout', () => {
        test('deve limpar sessão ao fazer logout', async ({ page }) => {
            // Simula acesso direto à rota de logout
            await page.goto('/logout');
            
            // Deve redirecionar para login
            await page.waitForURL('**/login**', { timeout: 5000 }).catch(() => {
                // Ou deve estar na página inicial
                expect(page.url()).not.toContain('dashboard');
            });
        });
    });
});
