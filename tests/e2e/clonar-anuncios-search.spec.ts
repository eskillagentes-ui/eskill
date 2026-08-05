import { test, expect, type Page } from './helpers/mutation-guard';

const email = process.env.E2E_TEST_USER_EMAIL;
const password = process.env.E2E_TEST_USER_PASSWORD;

async function login(page: Page) {
    await page.goto('/login');
    await page.fill('input[name="email"], input[type="email"]', email!);
    await page.fill('input[name="password"], input[type="password"]', password!);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForURL(/dashboard|login/, { timeout: 15000 });
}

test.describe('Clonar anúncios - busca', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(!email || !password, 'Credenciais E2E não configuradas');
        await login(page);
    });

    test('submete a busca com Enter e mantém o layout do botão em desktop', async ({ page }) => {
        let requestCount = 0;

        await page.route('**/api/catalog/clone/search**', async route => {
            requestCount += 1;
            await page.waitForTimeout(250);
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    status: 'success',
                    items: [{
                        id: 'MLB123456789',
                        title: "Suporte d'Guidão Premium",
                        seller_nickname: 'awa-motos',
                        listing_type_id: 'gold_special',
                        price: 129.9,
                        permalink: 'https://example.com/item/MLB123456789',
                        thumbnail: ''
                    }],
                    total: 1,
                    seller_id: '123456',
                    offset: 0,
                    limit: 20
                })
            });
        });

        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/dashboard/catalog/clonar-anuncios');

        const searchQuery = page.locator('#searchQuery');
        const searchButton = page.locator('#btnSearch');

        await expect(searchQuery).toHaveAttribute('type', 'search');
        await searchQuery.fill('bagageiro');
        await searchQuery.press('Enter');

        await expect(searchButton).toBeDisabled();
        await expect(searchButton).toHaveClass(/loading/);
        await expect(page.locator('#searchResultsWrapper')).toBeVisible();
        await expect(page.locator('#searchResultsCount')).toContainText('1 resultado');
        await expect(searchButton).not.toHaveClass(/loading/);

        const cloneButton = page.locator('.clone-result-btn');
        await expect(cloneButton).toBeVisible();
        await cloneButton.click();
        await expect(page.locator('#cloneModalItemInfo')).toContainText("Suporte d'Guidão Premium");
        expect(requestCount).toBe(1);
    });

    test('valida seller_id numérico e se adapta ao viewport mobile', async ({ page }) => {
        let requestTriggered = false;

        await page.route('**/api/catalog/clone/search**', async route => {
            requestTriggered = true;
            await route.abort();
        });

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/dashboard/catalog/clonar-anuncios');

        const searchType = page.locator('#searchType');
        const searchQuery = page.locator('#searchQuery');

        await searchType.selectOption('seller_id');
        await expect(searchQuery).toHaveAttribute('inputmode', 'numeric');
        await searchQuery.fill('abc123');
        await searchQuery.press('Enter');

        await expect(searchQuery).toHaveClass(/is-invalid/);
        await expect(page.locator('#searchQueryFeedback')).toContainText('apenas números');
        await expect(page.locator('#searchResultsWrapper')).toBeHidden();
        expect(requestTriggered).toBe(false);

        const bodyWidth = await page.evaluate(() => document.body.scrollWidth);
        const viewportWidth = await page.evaluate(() => window.innerWidth);
        expect(bodyWidth).toBeLessThanOrEqual(viewportWidth + 40);
    });
});
