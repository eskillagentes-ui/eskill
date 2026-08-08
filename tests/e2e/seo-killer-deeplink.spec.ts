import { test, expect, type Page } from './helpers/mutation-guard';

const email = process.env.E2E_TEST_USER_EMAIL;
const password = process.env.E2E_TEST_USER_PASSWORD;

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.fill('input[name="email"], input[type="email"]', email!);
    await page.fill('input[name="password"], input[type="password"]', password!);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForURL(/dashboard|login/, { timeout: 15000 });
}

test.describe('SEO Killer deep-link de abas', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(!email || !password, 'Credenciais E2E não configuradas');
        await login(page);
    });

    test('deve manter consistência entre deep-link, estado da aba e URL', async ({ page }) => {
        await page.goto('/dashboard/seo-killer#technical-sheet');
        await page.waitForLoadState('domcontentloaded');

        await expect(page.locator('#technical-sheet-tab')).toHaveClass(/active/);
        await expect(page.locator('#technical-sheet')).toHaveClass(/active/);
        await expect(page).toHaveURL(/\/dashboard\/seo-killer(?:\?tab=technical-sheet)?#technical-sheet$/);

        await page.click('#dashboard-tab');
        await expect(page.locator('#dashboard-tab')).toHaveClass(/active/);
        await expect(page.locator('#dashboard')).toHaveClass(/active/);
        await expect(page).toHaveURL(/\/dashboard\/seo-killer$/);

        await page.click('#technical-sheet-tab');
        await expect(page.locator('#technical-sheet-tab')).toHaveClass(/active/);
        await expect(page.locator('#technical-sheet')).toHaveClass(/active/);
        await expect(page).toHaveURL(/\/dashboard\/seo-killer\?tab=technical-sheet#technical-sheet$/);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.locator('#technical-sheet-tab')).toHaveClass(/active/);
        await expect(page.locator('#technical-sheet')).toHaveClass(/active/);
        await expect(page).toHaveURL(/\/dashboard\/seo-killer\?tab=technical-sheet#technical-sheet$/);
    });
});
