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
        
        # -> Fill 'admin@eskill.com.br' into the E-mail field and 'Awa@2026#Eskill!' into the Senha field, then click the 'Entrar na Plataforma' button.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill 'admin@eskill.com.br' into the E-mail field and 'Awa@2026#Eskill!' into the Senha field, then click the 'Entrar na Plataforma' button.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill 'admin@eskill.com.br' into the E-mail field and 'Awa@2026#Eskill!' into the Senha field, then click the 'Entrar na Plataforma' button.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Open the Financials page by clicking the 'Financeiro' / Financials link in the left sidebar (locate it after scrolling).
        await page.mouse.wheel(0, 300)
        
        # -> Click the 'Movimentações' link in the sidebar to open the Movimentações (Financial Movements) page.
        # Movimentações link
        elem = page.get_by_role('link', name='Movimentações', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Filtrar' button to apply the selected date range and confirm the page shows the empty-state message.
        # Filtrar button
        elem = page.locator('[id="mov-btn-filter"]')
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify an empty state message is visible
        # Assert: Empty-state message 'Nenhuma movimentação no período' is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[5]/div[1]/div/div[2]/div/table/tbody/tr/td").nth(0)).to_have_text("Nenhuma movimenta\u00e7\u00e3o no per\u00edodo", timeout=15000), "Empty-state message 'Nenhuma movimenta\u00e7\u00e3o no per\u00edodo' is visible."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    