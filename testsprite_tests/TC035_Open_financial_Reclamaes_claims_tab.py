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
        
        # -> Click the 'Entrar na Plataforma' button to sign in after filling the email and password fields.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Click the 'Entrar na Plataforma' button to sign in after filling the email and password fields.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Click the 'Entrar na Plataforma' button to sign in after filling the email and password fields.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Relatórios' link in the FINANCEIRO section of the sidebar to open the Financials area.
        # Relatórios link
        elem = page.locator('a[href="/dashboard/financials"]')
        await elem.click(timeout=10000)
        
        # -> Scroll down on the Financials page to reveal the in-page tabs area and expose the 'Reclamações' tab.
        await page.mouse.wheel(0, 300)
        
        # -> Click the 'Reclamações' tab button to open the claims panel in the Financials area.
        # Reclamações button
        elem = page.locator('[id="tab-claims-btn"]')
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the Reclamações/claims tab panel content is visible in the financials area
        await page.locator("xpath=/html/body/div[4]/main/div/ul/li[6]/button").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Reclamações' tab button is visible in the Financials tabs.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/ul/li[6]/button").nth(0)).to_be_visible(timeout=15000), "The 'Reclama\u00e7\u00f5es' tab button is visible in the Financials tabs."
        await page.locator("xpath=/html/body/div[4]/main/div/div[11]/div[6]/div[2]/div/div/div[2]/div/table/thead/tr").nth(0).scroll_into_view_if_needed()
        # Assert: The claims table header is visible inside the Reclamações panel.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[11]/div[6]/div[2]/div/div/div[2]/div/table/thead/tr").nth(0)).to_be_visible(timeout=15000), "The claims table header is visible inside the Reclama\u00e7\u00f5es panel."
        # Assert: The Reclamações panel shows the empty-state message 'Nenhuma reclamação no período.'.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[11]/div[6]/div[2]/div/div/div[2]/div/table/tbody/tr/td").nth(0)).to_have_text("Nenhuma reclama\u00e7\u00e3o no per\u00edodo.", timeout=15000), "The Reclama\u00e7\u00f5es panel shows the empty-state message 'Nenhuma reclama\u00e7\u00e3o no per\u00edodo.'."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    