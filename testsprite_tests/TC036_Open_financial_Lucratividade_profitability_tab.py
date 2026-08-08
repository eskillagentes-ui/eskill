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
        
        # -> Fill the email and password fields and click the 'Entrar na Plataforma' button to sign in.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the email and password fields and click the 'Entrar na Plataforma' button to sign in.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the email and password fields and click the 'Entrar na Plataforma' button to sign in.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Open the Financials page by navigating to /dashboard/financials (Dashboard → Financials).
        await page.goto("http://localhost:8877/dashboard/financials")
        try:
            await page.wait_for_load_state("domcontentloaded", timeout=5000)
        except Exception:
            pass
        
        # -> Find and open the 'Lucratividade' tab on the Financials page by locating its visible tab/button text.
        await page.mouse.wheel(0, 300)
        
        # -> Click the 'Lucratividade' tab button to open the profitability panel.
        # Lucratividade button
        elem = page.locator('[id="tab-profitability-btn"]')
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the Lucratividade/profitability tab panel is visible with content or empty-state
        # Assert: The 'Mais lucrativos' ranking shows the empty-state message 'Sem produtos neste ranking.'.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[10]/div[9]/div[2]/div[1]/div/div[2]/div/table/tbody/tr/td").nth(0)).to_have_text("Sem produtos neste ranking.", timeout=15000), "The 'Mais lucrativos' ranking shows the empty-state message 'Sem produtos neste ranking.'."
        # Assert: The 'Menos lucrativos' ranking shows the empty-state message 'Sem produtos neste ranking.'.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[10]/div[9]/div[2]/div[2]/div/div[2]/div/table/tbody/tr/td").nth(0)).to_have_text("Sem produtos neste ranking.", timeout=15000), "The 'Menos lucrativos' ranking shows the empty-state message 'Sem produtos neste ranking.'."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    