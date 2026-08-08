import asyncio
import re
from playwright import async_api
from playwright.async_api import expect

async def run_test():
    pw = None
    browser = None
    context = None

    try:
        # Start a Playwright session in asynchronous mode
        pw = await async_api.async_playwright().start()

        # Launch a Chromium browser in headless mode with custom arguments
        browser = await pw.chromium.launch(
            headless=True,
            args=[
                "--window-size=1280,720",
                "--disable-dev-shm-usage",
                "--ipc=host",
                "--single-process"
            ],
        )

        # Create a new browser context (like an incognito window)
        context = await browser.new_context()
        # Wider default timeout to match the agent's DOM-stability budget;
        # auto-waiting Playwright APIs (expect, locator.wait_for) inherit this.
        context.set_default_timeout(15000)

        # Open a new page in the browser context
        page = await context.new_page()

        # Interact with the page elements to simulate user flow
        # -> navigate
        await page.goto("http://localhost:8877/login")
        try:
            await page.wait_for_load_state("domcontentloaded", timeout=5000)
        except Exception:
            pass
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br, fill the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to submit the login form.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br, fill the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to submit the login form.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br, fill the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to submit the login form.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Relatórios' link under the Financeiro section in the left sidebar to open the Financials area.
        # Relatórios link
        elem = page.locator('a[href="/dashboard/financials"]')
        await elem.click(timeout=10000)
        
        # -> Click the 'Curva ABC' tab to open its panel and verify the panel displays a chart/table or a graceful empty/insufficient-data message.
        # Curva ABC button
        elem = page.locator('[id="tab-abc-btn"]')
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the Curva ABC tab panel is visible with chart/table content or a graceful empty/insufficient-data message — not a PHP error page
        await page.locator("xpath=/html/body/div[4]/main/div/ul/li[2]/button").nth(0).scroll_into_view_if_needed()
        # Assert: The Curva ABC tab button is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/ul/li[2]/button").nth(0)).to_be_visible(timeout=15000), "The Curva ABC tab button is visible."
        # Assert: The Curva ABC panel shows the graceful empty-state message 'Sem dados suficientes para análise ABC'.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Sem dados suficientes para an\u00e1lise ABC", timeout=15000), "The Curva ABC panel shows the graceful empty-state message 'Sem dados suficientes para an\u00e1lise ABC'."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    