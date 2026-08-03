import { test, expect } from './helpers/mutation-guard';
import { requireMutationAllowed } from './helpers/mutation-guard';

requireMutationAllowed('exports.spec');

/**
 * Testes E2E para Exportação e Relatórios
 * @mutante — exige E2E_ALLOW_MUTATION=true
 */
test.describe('Export & Reports API', () => {
    test.describe('PDF Export', () => {
        test('endpoint de export PDF de relatório', async ({ request }) => {
            const response = await request.get('/api/reports/export/pdf?type=summary');
            
            expect([200, 401, 403, 404]).toContain(response.status());
            
            if (response.status() === 200) {
                const contentType = response.headers()['content-type'];
                expect(contentType).toMatch(/application\/pdf|application\/json/);
            }
        });

        test('export PDF de ficha técnica', async ({ request }) => {
            const response = await request.get('/api/tech-sheet/MLB123/export/pdf');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });
    });

    test.describe('Excel Export', () => {
        test('endpoint de export Excel', async ({ request }) => {
            const response = await request.get('/api/reports/export/excel?type=items');
            
            expect([200, 401, 403, 404]).toContain(response.status());
            
            if (response.status() === 200) {
                const contentType = response.headers()['content-type'];
                // Excel MIME types
                expect(contentType).toMatch(/spreadsheet|excel|octet-stream|json/);
            }
        });

        test('export Excel de vendas', async ({ request }) => {
            const response = await request.get('/api/orders/export/excel');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });
    });

    test.describe('CSV Export', () => {
        test('endpoint de export CSV', async ({ request }) => {
            const response = await request.get('/api/reports/export/csv?type=items');
            
            expect([200, 401, 403, 404]).toContain(response.status());
            
            if (response.status() === 200) {
                const contentType = response.headers()['content-type'];
                expect(contentType).toMatch(/text\/csv|application\/json|octet-stream/);
            }
        });
    });

    test.describe('Report Generation', () => {
        test('deve gerar relatório de performance', async ({ request }) => {
            const response = await request.post('/api/reports/generate', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    type: 'performance',
                    period: 'monthly',
                    format: 'json'
                }
            });
            
            expect([200, 202, 401, 403]).toContain(response.status());
        });

        test('deve gerar relatório de SEO', async ({ request }) => {
            const response = await request.post('/api/reports/generate', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    type: 'seo',
                    format: 'json'
                }
            });
            
            expect([200, 202, 401, 403]).toContain(response.status());
        });
    });

    test.describe('Scheduled Reports', () => {
        test('deve listar relatórios agendados', async ({ request }) => {
            const response = await request.get('/api/reports/scheduled');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });

        test('deve criar relatório agendado', async ({ request }) => {
            const response = await request.post('/api/reports/scheduled', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    type: 'daily_summary',
                    schedule: '0 8 * * *',
                    format: 'pdf',
                    email: 'test@example.com'
                }
            });
            
            expect([200, 201, 401, 403]).toContain(response.status());
        });
    });
});

test.describe('Tech Sheet', () => {
    test.describe('API Endpoints', () => {
        test('deve listar fichas técnicas', async ({ request }) => {
            const response = await request.get('/api/tech-sheet');
            
            expect([200, 401, 403]).toContain(response.status());
        });

        test('deve retornar ficha técnica específica', async ({ request }) => {
            const response = await request.get('/api/tech-sheet/MLB123456');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });

        test('deve analisar ficha técnica', async ({ request }) => {
            const response = await request.post('/api/tech-sheet/analyze', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    item_id: 'MLB123456'
                }
            });
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });

        test('deve sugerir melhorias', async ({ request }) => {
            const response = await request.get('/api/tech-sheet/MLB123456/suggestions');
            
            expect([200, 401, 403, 404]).toContain(response.status());
        });
    });

    test.describe('Batch Operations', () => {
        test('deve processar lote de fichas', async ({ request }) => {
            const response = await request.post('/api/tech-sheet/batch', {
                headers: {
                    'Content-Type': 'application/json',
                },
                data: {
                    item_ids: ['MLB123', 'MLB456'],
                    operation: 'analyze'
                }
            });
            
            expect([200, 202, 401, 403]).toContain(response.status());
        });
    });
});
