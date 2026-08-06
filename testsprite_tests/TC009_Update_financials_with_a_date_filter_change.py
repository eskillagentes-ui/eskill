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
        
        # -> Fill the E-mail and Senha fields and click the 'Entrar na Plataforma' button to log into the staging platform.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the E-mail and Senha fields and click the 'Entrar na Plataforma' button to log into the staging platform.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the E-mail and Senha fields and click the 'Entrar na Plataforma' button to log into the staging platform.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Scroll down the Dashboard/left navigation to reveal the 'Financeiro' (Financials) link in the left sidebar.
        await page.mouse.wheel(0, 300)
        
        # -> Scroll the page to reveal the 'Financeiro' (Financials) link in the left navigation.
        await page.mouse.wheel(0, 300)
        
        # -> Click the 'Movimentações' link in the left navigation to open the Financials (Transactions) page.
        # Movimentações link
        elem = page.get_by_role('link', name='Movimentações', exact=True)
        await elem.click(timeout=10000)
        
        # -> Set 'Data inicial' to 01/07/2026 and 'Data final' to 31/07/2026, then click the 'Filtrar' button to apply the new date range.
        # date field
        elem = page.locator('[id="mov-date-start"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("2026-07-01")
        
        # -> Set 'Data inicial' to 01/07/2026 and 'Data final' to 31/07/2026, then click the 'Filtrar' button to apply the new date range.
        # date field
        elem = page.locator('[id="mov-date-end"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("2026-07-31")
        
        # -> Set 'Data inicial' to 01/07/2026 and 'Data final' to 31/07/2026, then click the 'Filtrar' button to apply the new date range.
        # Filtrar button
        elem = page.locator('[id="mov-btn-filter"]')
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the financial summary updates for the selected date range
        # Assert: The start date input is set to 2026-07-01.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div/div/div/form/div[1]/input").nth(0)).to_have_value("2026-07-01", timeout=15000), "The start date input is set to 2026-07-01."
        # Assert: The end date input is set to 2026-07-31.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div/div/div/form/div[2]/input").nth(0)).to_have_value("2026-07-31", timeout=15000), "The end date input is set to 2026-07-31."
        # Assert: The ledger table shows 'Nenhuma movimentação no período' for the selected range.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[5]/div[1]/div/div[2]/div/table/tbody/tr/td").nth(0)).to_have_text("Nenhuma movimenta\u00e7\u00e3o no per\u00edodo", timeout=15000), "The ledger table shows 'Nenhuma movimenta\u00e7\u00e3o no per\u00edodo' for the selected range."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    