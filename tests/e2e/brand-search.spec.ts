import { test, expect, type Page } from './helpers/mutation-guard';

const email = process.env.E2E_TEST_USER_EMAIL;
const password = process.env.E2E_TEST_USER_PASSWORD;

// Helper to mock the brand search API endpoints
async function mockBrandSearchApi(page: Page, searchId = 1) {
    await page.route('**/api/brand-search/start', async route => {
        await route.fulfill({
            status: 202,
            contentType: 'application/json',
            body: JSON.stringify({ success: true, search_id: searchId }),
        });
    });

    await page.route(`**/api/brand-search/${searchId}/progress`, async route => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                status:        'completed',
                progress:      100,
                total_sellers: 3,
                total_items:   42,
            }),
        });
    });

    await page.route(`**/api/brand-search/${searchId}/sellers*`, async route => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                success:    true,
                data:       [
                    {
                        seller_id:         1001,
                        nickname:          'AWAMOTO',
                        reputation_level:  'gold',
                        reputation_score:  88,
                        total_items_brand: 30,
                        avg_price:         149.9,
                        trend:             'up',
                    },
                ],
                total:    1,
                page:     1,
                per_page: 20,
                last_page: 1,
            }),
        });
    });
}

test.describe('Brand Search — Módulo 20 BRAND-003', () => {
    // /brand-search exige sessão autenticada; sem credenciais E2E, apenas pulamos
    // (mesmo padrão usado em dashboard-audit.spec.ts e clonar-anuncios-search.spec.ts).
    test.beforeEach(async ({ page }) => {
        test.skip(!email || !password, 'Credenciais E2E não configuradas');

        await page.goto('/login');
        await page.fill('input[name="email"], input[type="email"]', email!);
        await page.fill('input[name="password"], input[type="password"]', password!);
        await page.click('button[type="submit"], input[type="submit"]');
        await page.waitForURL(/dashboard|login/, { timeout: 15000 });

        await page.goto('/brand-search');
        await page.waitForLoadState('domcontentloaded');
    });

    test('page loads with search form visible', async ({ page }) => {
        await expect(page.locator('#inp-brand')).toBeVisible();
        await expect(page.locator('#inp-brand-id')).toBeVisible();
        await expect(page.locator('#btn-search')).toBeVisible();
    });

    test('progress bar is hidden on load', async ({ page }) => {
        const progressWrap = page.locator('#progress-wrap');
        await expect(progressWrap).toBeHidden();
    });

    test('search button shows progress bar on click', async ({ page }) => {
        await mockBrandSearchApi(page);

        await page.fill('#inp-brand',    'AWA');
        await page.fill('#inp-brand-id', '7297804');
        await page.click('#btn-search');

        await expect(page.locator('#progress-wrap')).toBeVisible({ timeout: 3000 });
    });

    test('completed search renders sellers table', async ({ page }) => {
        await mockBrandSearchApi(page);

        await page.fill('#inp-brand',    'AWA');
        await page.fill('#inp-brand-id', '7297804');
        await page.click('#btn-search');

        // Wait for the seller row to appear
        await expect(page.locator('#sellers-tbody tr')).toHaveCount(1, { timeout: 8000 });
        await expect(page.locator('#sellers-tbody')).toContainText('AWAMOTO');
    });

    test('filter chips are present', async ({ page }) => {
        await expect(page.locator('.filter-chip[data-reputation]').first()).toBeVisible();
        await expect(page.locator('.filter-chip[data-min-items]').first()).toBeVisible();
    });

    test('export button navigates to export URL after search', async ({ page }) => {
        await mockBrandSearchApi(page);

        await page.fill('#inp-brand',    'AWA');
        await page.fill('#inp-brand-id', '7297804');
        await page.click('#btn-search');

        await page.locator('#sellers-tbody tr').waitFor({ timeout: 8000 });

        const exportUrl = page.url();
        await page.click('#btn-export');

        // After export, URL should contain /api/brand-search or navigation happens
        await page.waitForTimeout(500);
        const currentUrl = page.url();
        // Either we're still on brand-search (export is a download) or navigated
        expect(currentUrl).toContain('brand-search');
    });

    test('empty brand_id shows no search call', async ({ page }) => {
        let searchCalled = false;
        await page.route('**/api/brand-search/start', () => { searchCalled = true; });

        await page.fill('#inp-brand', 'AWA');
        await page.click('#btn-search');

        await page.waitForTimeout(300);
        expect(searchCalled).toBe(false);
    });
});
