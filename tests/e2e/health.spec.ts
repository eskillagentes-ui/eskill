import { test, expect } from './helpers/mutation-guard';

test.describe('Health Check', () => {
    test('should respond to health endpoint', async ({ request }) => {
        const response = await request.get('/api/health');

        expect(response.ok()).toBeTruthy();
        expect(response.status()).toBe(200);

        const data = await response.json();
        expect(data).toHaveProperty('status');
    });

    test('should respond to live endpoint', async ({ request }) => {
        const response = await request.get('/api/health/live');

        expect(response.ok()).toBeTruthy();
        expect(response.status()).toBe(200);
    });
});
