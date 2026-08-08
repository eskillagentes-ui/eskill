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
        
        # -> Click the 'Entrar na Plataforma' button to submit the login form after filling email and password.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Click the 'Entrar na Plataforma' button to submit the login form after filling email and password.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Click the 'Entrar na Plataforma' button to submit the login form after filling email and password.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Scroll the page/sidebar to reveal more navigation items and find the 'Financeiro' link in the sidebar.
        await page.mouse.wheel(0, 300)
        
        # -> Click the 'Relatórios' link under the FINANCEIRO section in the sidebar to open the Financials page.
        # Relatórios link
        elem = page.locator('a[href="/dashboard/financials"]')
        await elem.click(timeout=10000)
        
        # -> Set 'Data Inicial' to 07/01/2026 and 'Data Final' to 07/31/2026, then click the 'Filtrar' button to apply the new date range.
        # start date field
        elem = page.locator('[id="date-start"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("2026-07-01")
        
        # -> Set 'Data Inicial' to 07/01/2026 and 'Data Final' to 07/31/2026, then click the 'Filtrar' button to apply the new date range.
        # end date field
        elem = page.locator('[id="date-end"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("2026-07-31")
        
        # -> Set 'Data Inicial' to 07/01/2026 and 'Data Final' to 07/31/2026, then click the 'Filtrar' button to apply the new date range.
        # Filtrar button
        elem = page.locator('[id="btn-financial-filter"]')
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the financial summary updates for the selected date range
        # Assert: The Data Inicial input shows 2026-07-01, confirming the start date was applied.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div/div/div/form/div[1]/input").nth(0)).to_have_value("2026-07-01", timeout=15000), "The Data Inicial input shows 2026-07-01, confirming the start date was applied."
        # Assert: The Data Final input shows 2026-07-31, confirming the end date was applied.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div/div/div/form/div[2]/input").nth(0)).to_have_value("2026-07-31", timeout=15000), "The Data Final input shows 2026-07-31, confirming the end date was applied."
        await page.locator("xpath=/html/body/div[4]/main/div/div[8]/div[1]/button").nth(0).scroll_into_view_if_needed()
        # Assert: The financial summary's 'Ver detalhe' button is visible, indicating the summary cards were rendered for the selected range.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[8]/div[1]/button").nth(0)).to_be_visible(timeout=15000), "The financial summary's 'Ver detalhe' button is visible, indicating the summary cards were rendered for the selected range."
        # Assert: The page URL contains /dashboard/financials, confirming the Financials page is open.
        await expect(page).to_have_url(re.compile("/dashboard/financials"), timeout=15000), "The page URL contains /dashboard/financials, confirming the Financials page is open."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    