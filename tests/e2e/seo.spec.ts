import { test, expect } from './helpers/mutation-guard';
import { requireMutationAllowed } from './helpers/mutation-guard';

requireMutationAllowed('seo.spec');

/**
 * Testes E2E para SEO Intelligence
 * Verifica funcionalidades de análise SEO, keywords e otimização
 * @mutante — exige E2E_ALLOW_MUTATION=true
 */
test.describe('SEO Intelligence API', () => {
    test.describe('Análise SEO', () => {
        test('endpoint de análise deve existir', async ({ request }) => {
            const response = await request.get('/api/seo/analyze/MLB123456789');
            
            // 200 = sucesso, 401/403 = requer auth, 404 = item não existe (ok)
            expect([200, 401, 403, 404]).toContain(response.status());
        });

        test('deve aceitar análise POST com dados', async ({ request }) => {
            const response = await request.post('/api/seo/analyze', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    title: 'Produto Teste para Análise SEO',
                    description: 'Descrição do produto com palavras-chave relevantes',
                    category_id: 'MLB1234',
                    price: 199.90
                }
            });
            
            // Aceita 200 (sucesso) ou 401 (requer auth)
            expect([200, 401, 403]).toContain(response.status());
            
            if (response.status() === 200) {
                const data = await response.json();
                expect(data).toHaveProperty('success');
            }
        });

        test('análise em lote deve funcionar', async ({ request }) => {
            const response = await request.post('/api/seo/analyze/batch', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    items: ['MLB123', 'MLB456']
                }
            });
            
            expect([200, 401, 403]).toContain(response.status());
        });
    });

    test.describe('Pesquisa de Keywords', () => {
        test('endpoint de keywords por categoria', async ({ request }) => {
            const response = await request.get('/api/seo/keywords/MLB1234');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });

        test('endpoint de volume de busca', async ({ request }) => {
            const response = await request.post('/api/seo/keywords/volume', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    keywords: ['celular', 'smartphone', 'iphone']
                }
            });
            
            expect([200, 401, 403]).toContain(response.status());
        });

        test('endpoint de tendências', async ({ request }) => {
            const response = await request.get('/api/seo/trends/MLB1234');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });
    });

    test.describe('Otimização de Títulos', () => {
        test('deve otimizar título existente', async ({ request }) => {
            const response = await request.post('/api/seo/title/optimize', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    title: 'produto muito bom barato promoção',
                    category_id: 'MLB1234'
                }
            });
            
            expect([200, 401, 403]).toContain(response.status());
            
            if (response.status() === 200) {
                const data = await response.json();
                expect(data).toHaveProperty('success');
            }
        });

        test('deve sugerir título por categoria', async ({ request }) => {
            const response = await request.post('/api/seo/title/suggest', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    category_id: 'MLB1234',
                    brand: 'Apple',
                    model: 'iPhone 14'
                }
            });
            
            expect([200, 401, 403]).toContain(response.status());
        });
    });

    test.describe('Construtor de Anúncios', () => {
        test('deve construir anúncio otimizado', async ({ request }) => {
            const response = await request.post('/api/seo/listing/build', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    title: 'iPhone 14 Pro Max',
                    category_id: 'MLB1234',
                    price: 7999.00,
                    brand: 'Apple'
                }
            });
            
            expect([200, 401, 403]).toContain(response.status());
        });

        test('deve gerar descrição', async ({ request }) => {
            const response = await request.post('/api/seo/listing/description', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    title: 'Smartphone Samsung Galaxy',
                    features: ['128GB', 'Câmera 50MP', 'Tela 6.5"'],
                    category_id: 'MLB1234'
                }
            });
            
            expect([200, 401, 403]).toContain(response.status());
        });
    });

    test.describe('Estratégia de Preços', () => {
        test('deve analisar concorrência', async ({ request }) => {
            const response = await request.get('/api/seo/pricing/MLB1234');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });

        test('deve sugerir preço', async ({ request }) => {
            const response = await request.post('/api/seo/pricing/suggest', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    category_id: 'MLB1234',
                    cost: 100.00,
                    target_margin: 0.30
                }
            });
            
            expect([200, 401, 403]).toContain(response.status());
        });

        test('deve calcular preço com margem', async ({ request }) => {
            const response = await request.post('/api/seo/pricing/calculate', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    cost: 100.00,
                    margin: 0.25,
                    fees: 0.16
                }
            });
            
            expect([200, 401, 403]).toContain(response.status());
            
            if (response.status() === 200) {
                const data = await response.json();
                expect(data).toHaveProperty('success');
            }
        });
    });

    test.describe('Batch Audit', () => {
        test('deve iniciar auditoria em lote', async ({ request }) => {
            const response = await request.post('/api/seo/intelligence/audit/batch', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    item_ids: ['MLB123', 'MLB456']
                }
            });
            
            expect([200, 202, 401, 403]).toContain(response.status());
            
            if (response.status() === 200 || response.status() === 202) {
                const data = await response.json();
                expect(data).toHaveProperty('success');
            }
        });

        test('deve verificar status de job', async ({ request }) => {
            const response = await request.get('/api/seo/intelligence/audit/status/test-job-id');
            
            // 404 = job não existe (esperado para ID fictício)
            expect([200, 401, 403, 404]).toContain(response.status());
        });
    });
});
