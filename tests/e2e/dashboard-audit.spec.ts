import { test, expect } from './helpers/mutation-guard';

const email = process.env.E2E_TEST_USER_EMAIL;
const password = process.env.E2E_TEST_USER_PASSWORD;

test.describe('Dashboard audit', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(!email || !password, 'Credenciais E2E não configuradas');

        await page.goto('/login');
        await page.fill('input[name="email"], input[type="email"]', email!);
        await page.fill('input[name="password"], input[type="password"]', password!);
        await page.click('button[type="submit"], input[type="submit"]');
        await page.waitForURL(/dashboard|auth\/login|login/, { timeout: 15000 });
    });

    test('dashboard deve carregar widgets principais sem erro crítico de console', async ({ page }) => {
        const consoleErrors: string[] = [];
        page.on('console', msg => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });

        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('heading', { name: 'Bem-vindo ao seu Dashboard' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Contas Conectadas' })).toBeVisible();
        await expect(page.getByText('Pedidos (30 dias)')).toBeVisible();

        const criticalErrors = consoleErrors.filter(error =>
            !error.includes('favicon') &&
            !error.includes('third-party')
        );

        expect(criticalErrors).toEqual([]);
    });

    test('dashboard deve ser utilizável em mobile e desktop', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');

        const bodyWidth = await page.evaluate(() => document.body.scrollWidth);
        const viewportWidth = await page.evaluate(() => window.innerWidth);
        expect(bodyWidth).toBeLessThanOrEqual(viewportWidth + 40);

        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('text=Nova Conta')).toBeVisible();
    });

    test('atalhos do dashboard devem responder', async ({ page }) => {
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');

        const connectAccount = page.getByRole('link', { name: /Conectar Conta|Nova Conta/ }).first();
        await expect(connectAccount).toBeVisible();
        await expect(connectAccount).toHaveAttribute('href', /\/auth\/authorize/);

        const ordersLink = page.getByRole('link', { name: 'Ver Pedidos' });
        await ordersLink.click();
        await page.waitForURL(/dashboard\/orders/, { timeout: 15000 });
        await expect(page).toHaveURL(/dashboard\/orders/);
    });
});
