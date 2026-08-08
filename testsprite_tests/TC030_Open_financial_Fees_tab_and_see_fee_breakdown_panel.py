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
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br and the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to log in.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br and the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to log in.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br and the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to log in.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Open the 'Financials' page (navigate to /dashboard/financials).
        await page.goto("http://localhost:8877/dashboard/financials")
        try:
            await page.wait_for_load_state("domcontentloaded", timeout=5000)
        except Exception:
            pass
        
        # -> Click the 'Ver detalhe' button to open the Fees details panel
        # Ver detalhe button
        elem = page.locator('[id="btn-goto-fees-tab"]')
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the Fees tab panel is active and fee breakdown content is visible in the financials main area
        await page.locator("xpath=/html/body/div[4]/main/div/ul/li[3]/button").nth(0).scroll_into_view_if_needed()
        # Assert: The Fees tab ('Taxas & Custos') is visible and active.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/ul/li[3]/button").nth(0)).to_be_visible(timeout=15000), "The Fees tab ('Taxas & Custos') is visible and active."
        await page.locator("xpath=/html/body/div[4]/main/div/div[11]/div[3]/div[2]/div[1]/div/div[2]/div/table/thead/tr").nth(0).scroll_into_view_if_needed()
        # Assert: The fee breakdown table header is visible in the Fees panel.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[11]/div[3]/div[2]/div[1]/div/div[2]/div/table/thead/tr").nth(0)).to_be_visible(timeout=15000), "The fee breakdown table header is visible in the Fees panel."
        # Assert: The fee breakdown includes the 'Comissão ML' row.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[11]/div[3]/div[2]/div[1]/div/div[2]/div/table/tbody/tr[1]/td[1]").nth(0)).to_have_text("Comiss\u00e3o ML", timeout=15000), "The fee breakdown includes the 'Comiss\u00e3o ML' row."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    