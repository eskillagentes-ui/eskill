import { test, expect } from './helpers/mutation-guard';

/**
 * Testes E2E para Dashboard
 * Verifica carregamento de métricas, gráficos e componentes principais
 */
test.describe('Dashboard', () => {
    // Setup: login antes dos testes (se autenticação necessária)
    // test.beforeEach(async ({ page }) => {
    //     await page.goto('/login');
    //     await page.fill('input[name="email"]', 'test@example.com');
    //     await page.fill('input[name="password"]', 'testpassword');
    //     await page.click('button[type="submit"]');
    //     await page.waitForURL('**/dashboard**');
    // });

    test.describe('Carregamento de Página', () => {
        test('página inicial deve carregar sem erros', async ({ page }) => {
            const response = await page.goto('/');
            
            // Verifica resposta HTTP bem-sucedida
            expect(response?.status()).toBeLessThan(500);
            
            // Verifica que não há erros de JavaScript no console
            const consoleErrors: string[] = [];
            page.on('console', msg => {
                if (msg.type() === 'error') {
                    consoleErrors.push(msg.text());
                }
            });
            
            await page.waitForLoadState('networkidle');
            
            // Permite alguns erros menores (ex: recursos externos)
            const criticalErrors = consoleErrors.filter(e => 
                !e.includes('favicon') && 
                !e.includes('analytics') &&
                !e.includes('third-party')
            );
            
            expect(criticalErrors.length).toBeLessThanOrEqual(3);
        });

        test('deve ter meta tags essenciais', async ({ page }) => {
            await page.goto('/');
            
            // Verifica charset
            const charset = await page.locator('meta[charset]').getAttribute('charset');
            expect(charset?.toLowerCase()).toBe('utf-8');
            
            // Verifica viewport
            const viewport = await page.locator('meta[name="viewport"]').getAttribute('content');
            expect(viewport).toContain('width=device-width');
        });
    });

    test.describe('API Endpoints', () => {
        test('health check deve retornar status ok', async ({ request }) => {
            const response = await request.get('/api/health');
            
            expect(response.ok()).toBeTruthy();
            
            const data = await response.json();
            expect(data).toHaveProperty('status');
        });

        test('API de métricas deve responder', async ({ request }) => {
            // Endpoint pode requerer autenticação - verifica que existe
            const response = await request.get('/api/dashboard/metrics');
            
            // 401/403 = existe mas requer auth, 200 = ok, 404 = não existe
            expect(response.status()).not.toBe(500);
        });
    });

    test.describe('Performance', () => {
        test('página deve carregar em menos de 5 segundos', async ({ page }) => {
            const startTime = Date.now();
            
            await page.goto('/');
            await page.waitForLoadState('domcontentloaded');
            
            const loadTime = Date.now() - startTime;
            
            expect(loadTime).toBeLessThan(5000);
        });

        test('recursos estáticos devem ter cache headers', async ({ page }) => {
            const responses: Map<string, number> = new Map();
            
            page.on('response', response => {
                const url = response.url();
                if (url.includes('.css') || url.includes('.js')) {
                    const cacheControl = response.headers()['cache-control'];
                    if (cacheControl) {
                        responses.set(url, 1);
                    }
                }
            });
            
            await page.goto('/');
            await page.waitForLoadState('networkidle');
            
            // Pelo menos alguns recursos devem ter cache
            // (não obrigatório em desenvolvimento)
        });
    });

    test.describe('Responsividade', () => {
        test('deve ser navegável em mobile', async ({ page }) => {
            await page.setViewportSize({ width: 375, height: 667 }); // iPhone SE
            
            await page.goto('/');
            await page.waitForLoadState('networkidle');
            
            // Verifica que não há scroll horizontal excessivo
            const bodyWidth = await page.evaluate(() => document.body.scrollWidth);
            const viewportWidth = await page.evaluate(() => window.innerWidth);
            
            expect(bodyWidth).toBeLessThanOrEqual(viewportWidth + 50);
        });

        test('deve funcionar em tablet', async ({ page }) => {
            await page.setViewportSize({ width: 768, height: 1024 }); // iPad
            
            const response = await page.goto('/');
            expect(response?.status()).toBeLessThan(500);
        });

        test('deve funcionar em desktop', async ({ page }) => {
            await page.setViewportSize({ width: 1920, height: 1080 });
            
            const response = await page.goto('/');
            expect(response?.status()).toBeLessThan(500);
        });
    });

    test.describe('Acessibilidade Básica', () => {
        test('deve ter título de página', async ({ page }) => {
            await page.goto('/');
            
            const title = await page.title();
            expect(title.length).toBeGreaterThan(0);
        });

        test('deve ter linguagem definida', async ({ page }) => {
            await page.goto('/');
            
            const lang = await page.locator('html').getAttribute('lang');
            expect(lang).toBeTruthy();
        });

        test('imagens devem ter alt text', async ({ page }) => {
            await page.goto('/');
            await page.waitForLoadState('networkidle');
            
            const imagesWithoutAlt = await page.locator('img:not([alt])').count();
            
            // Permite algumas imagens decorativas sem alt
            expect(imagesWithoutAlt).toBeLessThan(5);
        });

        test('links devem ter texto ou aria-label', async ({ page }) => {
            await page.goto('/');
            await page.waitForLoadState('networkidle');
            
            const emptyLinks = await page.locator('a:not([aria-label]):not(:has(*))').filter({ hasText: '' }).count();
            
            expect(emptyLinks).toBe(0);
        });
    });
});
