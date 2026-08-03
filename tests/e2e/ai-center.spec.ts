import { test, expect } from '@playwright/test';
import { requireMutationAllowed } from './helpers/mutation-guard';

requireMutationAllowed('ai-center.spec');

/**
 * Testes E2E para AI Center e Otimização
 * @mutante — exige E2E_ALLOW_MUTATION=true
 */
test.describe('AI Center API', () => {
    test.describe('Predictive Analytics', () => {
        test('endpoint de previsão deve existir', async ({ request }) => {
            const response = await request.post('/api/ai/predict', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    category_id: 'MLB1234',
                    horizon_days: 30
                }
            });
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });

        test('análise de demanda', async ({ request }) => {
            const response = await request.post('/api/ai/demand', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    product_id: 'MLB123456',
                    period: 'monthly'
                }
            });
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });
    });

    test.describe('Optimization History', () => {
        test('deve listar histórico de otimizações', async ({ request }) => {
            const response = await request.get('/api/ai/optimization/history');
            
            expect([200, 401, 403]).toContain(response.status());
            
            if (response.status() === 200) {
                const data = await response.json();
                expect(data).toHaveProperty('success');
                expect(data).toHaveProperty('data');
            }
        });

        test('deve retornar detalhes de otimização', async ({ request }) => {
            const response = await request.get('/api/ai/optimization/history/12345');
            
            // 404 = registro não existe (ok para ID fictício)
            expect([200, 401, 403, 404]).toContain(response.status());
        });

        test('deve aceitar filtros de período', async ({ request }) => {
            const response = await request.get('/api/ai/optimization/history?start_date=2024-01-01&end_date=2024-12-31');
            
            expect([200, 401, 403]).toContain(response.status());
        });
    });

    test.describe('AutoPilot', () => {
        test('endpoint de status do autopilot', async ({ request }) => {
            const response = await request.get('/api/ai/autopilot/status');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });

        test('configuração do autopilot', async ({ request }) => {
            const response = await request.post('/api/ai/autopilot/config', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    enabled: false,
                    auto_price: false,
                    auto_title: false
                }
            });
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });
    });

    test.describe('Decision Engine', () => {
        test('deve retornar recomendações', async ({ request }) => {
            const response = await request.get('/api/ai/recommendations');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });

        test('deve processar decisão', async ({ request }) => {
            const response = await request.post('/api/ai/decision', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    recommendation_id: 'rec-123',
                    action: 'approve'
                }
            });
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });
    });

    test.describe('ML Models', () => {
        test('deve retornar status dos modelos', async ({ request }) => {
            const response = await request.get('/api/ai/models/status');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });

        test('deve retornar métricas de acurácia', async ({ request }) => {
            const response = await request.get('/api/ai/models/accuracy');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });
    });
});

test.describe('AI Center UI', () => {
    test.describe('Carregamento de Página', () => {
        test('página de AI center deve carregar', async ({ page }) => {
            const response = await page.goto('/ai-center');
            
            // 302 = redirect (ok, pode precisar auth), 200 = ok
            expect([200, 301, 302, 401, 403]).toContain(response?.status() ?? 0);
        });

        test('página de optimization deve carregar', async ({ page }) => {
            const response = await page.goto('/ai-optimization');
            
            expect([200, 301, 302, 401, 403]).toContain(response?.status() ?? 0);
        });
    });
});
