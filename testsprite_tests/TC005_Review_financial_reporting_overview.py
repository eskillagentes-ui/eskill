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
        
        # -> Fill 'admin@eskill.com.br' into the E-mail field
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill 'admin@eskill.com.br' into the E-mail field
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill 'admin@eskill.com.br' into the E-mail field
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Scroll the page to reveal the left navigation items so the 'Financeiro' (Financials) link becomes visible.
        await page.mouse.wheel(0, 300)
        
        # -> Click the 'Movimentações' link in the left navigation to open the Financials module.
        # Movimentações link
        elem = page.get_by_role('link', name='Movimentações', exact=True)
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify PnL information is displayed
        await page.locator("xpath=/html/body/div[4]/main/div/div[3]/div/div/div/form/div[4]/div/a[1]").nth(0).scroll_into_view_if_needed()
        # Assert: The DRE (PnL) link is visible on the Financials page.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div/div/div/form/div[4]/div/a[1]").nth(0)).to_be_visible(timeout=15000), "The DRE (PnL) link is visible on the Financials page."
        await page.locator("xpath=/html/body/div[4]/main/div/div[5]/div[1]/div/div[1]/span").nth(0).scroll_into_view_if_needed()
        # Assert: A PnL summary widget value of "0" is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[5]/div[1]/div/div[1]/span").nth(0)).to_be_visible(timeout=15000), "A PnL summary widget value of \"0\" is visible."
        await page.locator("xpath=/html/body/div[4]/main/div/div[5]/div[1]/div/div[2]/div/table/tbody/tr/td").nth(0).scroll_into_view_if_needed()
        # Assert: The ledger area displays the 'Nenhuma movimentação no período' message, confirming the PnL section is present.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[5]/div[1]/div/div[2]/div/table/tbody/tr/td").nth(0)).to_be_visible(timeout=15000), "The ledger area displays the 'Nenhuma movimenta\u00e7\u00e3o no per\u00edodo' message, confirming the PnL section is present."
        
        # --> Verify financial summary widgets are visible
        # Assert: A financial summary widget is visible and displays the value "0".
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[5]/div[1]/div/div[1]/span").nth(0)).to_have_text("0", timeout=15000), "A financial summary widget is visible and displays the value \"0\"."
        # Assert: The ledger area is visible and shows "Nenhuma movimentação no período".
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[5]/div[1]/div/div[2]/div/table/tbody/tr/td").nth(0)).to_have_text("Nenhuma movimenta\u00e7\u00e3o no per\u00edodo", timeout=15000), "The ledger area is visible and shows \"Nenhuma movimenta\u00e7\u00e3o no per\u00edodo\"."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    