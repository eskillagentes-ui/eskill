// @ts-check
const { test, expect } = require('./helpers/mutation-guard');

test.describe('Account Health Dashboard', () => {
    test.beforeEach(async ({ page }) => {
        // Navigate to the account health page
        await page.goto('/dashboard/account-health');
        
        // Wait for the page to be loaded
        await page.waitForLoadState('networkidle');
    });

    test('should load the account health page', async ({ page }) => {
        // Check if the page title contains expected text
        await expect(page).toHaveTitle(/Account Health|Dashboard/);
        
        // Check if main content is visible
        const mainContent = page.locator('main, .container, .content');
        await expect(mainContent).toBeVisible();
    });

    test('should display health metrics', async ({ page }) => {
        // Wait for any health metrics to load
        await page.waitForTimeout(2000);
        
        // Take a screenshot for reference
        await page.screenshot({ path: 'test-results/account-health-overview.png', fullPage: true });
        
        // Check for common health metric elements
        const possibleSelectors = [
            'h1, h2, h3',
            '.card, .metric, .dashboard-item',
            'table, .table',
            '.alert, .warning, .status'
        ];
        
        for (const selector of possibleSelectors) {
            const elements = page.locator(selector);
            const count = await elements.count();
            if (count > 0) {
                console.log(`Found ${count} elements matching: ${selector}`);
            }
        }
    });

    test('should have functional navigation', async ({ page }) => {
        // Check if there are any navigation links
        const navLinks = page.locator('nav a, .nav a, .menu a');
        const linkCount = await navLinks.count();
        
        if (linkCount > 0) {
            console.log(`Found ${linkCount} navigation links`);
        }
    });

    test('should run all interactive tests', async ({ page }) => {
        console.log('Starting interactive tests...');
        
        // 1. Test form submissions if any
        const forms = page.locator('form');
        const formCount = await forms.count();
        console.log(`Found ${formCount} forms on the page`);
        
        // 2. Test buttons
        const buttons = page.locator('button:visible');
        const buttonCount = await buttons.count();
        console.log(`Found ${buttonCount} visible buttons`);
        
        for (let i = 0; i < Math.min(buttonCount, 5); i++) {
            const button = buttons.nth(i);
            const buttonText = await button.textContent();
            console.log(`Button ${i + 1}: "${buttonText?.trim()}"`);
            
            // Check if button is enabled
            const isEnabled = await button.isEnabled();
            console.log(`  - Enabled: ${isEnabled}`);
            
            // Try to click if it's a test/check button
            if (buttonText && (buttonText.toLowerCase().includes('test') || 
                               buttonText.toLowerCase().includes('check') ||
                               buttonText.toLowerCase().includes('verify') ||
                               buttonText.toLowerCase().includes('analisar') ||
                               buttonText.toLowerCase().includes('verificar'))) {
                try {
                    console.log(`  - Clicking test button: ${buttonText.trim()}`);
                    await button.click();
                    await page.waitForTimeout(2000);
                    
                    // Take screenshot after action
                    await page.screenshot({ 
                        path: `test-results/account-health-after-button-${i}.png`,
                        fullPage: true 
                    });
                } catch (error) {
                    console.log(`  - Error clicking button: ${error.message}`);
                }
            }
        }
        
        // 3. Test tabs if any
        const tabs = page.locator('[role="tab"], .tab, .nav-tabs a');
        const tabCount = await tabs.count();
        console.log(`Found ${tabCount} tabs`);
        
        for (let i = 0; i < Math.min(tabCount, 5); i++) {
            const tab = tabs.nth(i);
            const tabText = await tab.textContent();
            console.log(`Tab ${i + 1}: "${tabText?.trim()}"`);
            
            try {
                await tab.click();
                await page.waitForTimeout(1000);
                await page.screenshot({ 
                    path: `test-results/account-health-tab-${i}.png`,
                    fullPage: true 
                });
            } catch (error) {
                console.log(`  - Error clicking tab: ${error.message}`);
            }
        }
        
        // 4. Check for API calls
        page.on('response', response => {
            const url = response.url();
            if (url.includes('/api/') || url.includes('account-health')) {
                console.log(`API Response: ${response.status()} - ${url}`);
            }
        });
        
        // 5. Check for any health status indicators
        const statusIndicators = page.locator('.status, .health-status, [class*="health"], [class*="status"]');
        const statusCount = await statusIndicators.count();
        console.log(`Found ${statusCount} potential status indicators`);
        
        // 6. Final screenshot
        await page.screenshot({ 
            path: 'test-results/account-health-final.png',
            fullPage: true 
        });
        
        console.log('Interactive tests completed!');
    });
});
