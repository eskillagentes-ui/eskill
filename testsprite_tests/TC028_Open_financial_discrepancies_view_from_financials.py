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
        
        # -> Fill the E-mail field with admin@eskill.com.br, fill the Senha field with the provided password, then click the 'Entrar na Plataforma' button.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the E-mail field with admin@eskill.com.br, fill the Senha field with the provided password, then click the 'Entrar na Plataforma' button.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the E-mail field with admin@eskill.com.br, fill the Senha field with the provided password, then click the 'Entrar na Plataforma' button.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Scroll down the dashboard left navigation / page to reveal the 'Financials' (Financeiro) link in the sidebar.
        await page.mouse.wheel(0, 300)
        
        # -> Scroll the dashboard to reveal the 'Financials' (Financeiro) link in the left navigation and inspect all sidebar links.
        await page.mouse.wheel(0, 300)
        
        # -> Type 'financeiro' into the sidebar 'Buscar...' search field and wait for the navigation to filter or suggestions to appear.
        # Buscar... text field
        elem = page.locator('[id="sidebarSearch"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("financeiro")
        
        # -> Click the 'Financeiro' link in the left navigation to open the Financials section.
        # ⌘K
        elem = page.locator('xpath=/html/body/aside/div[3]')
        await elem.click(timeout=10000)
        
        # -> Open the 'Divergências do Ledger' (Discrepancies) conciliation view from Financials → Conciliation and verify it loads without requiring upload/sync.
        await page.goto("http://localhost:8877/dashboard/financials/conciliation?view=discrepancies")
        try:
            await page.wait_for_load_state("domcontentloaded", timeout=5000)
        except Exception:
            pass
        
        # --> Assertions to verify final state
        
        # --> Open /dashboard/financials/conciliation?view=discrepancies from financials navigation
        # Assert: Current URL contains the discrepancies conciliation path.
        await expect(page).to_have_url(re.compile("/dashboard/financials/conciliation\\?view=discrepancies"), timeout=15000), "Current URL contains the discrepancies conciliation path."
        await page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/table/thead/tr").nth(0).scroll_into_view_if_needed()
        # Assert: Conciliation table header is visible, indicating the Discrepancies view loaded.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/table/thead/tr").nth(0)).to_be_visible(timeout=15000), "Conciliation table header is visible, indicating the Discrepancies view loaded."
        await page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/div/div[2]/button").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Filtrar' button is visible, confirming filters are present on the Discrepancies page.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/div/div[2]/button").nth(0)).to_be_visible(timeout=15000), "The 'Filtrar' button is visible, confirming filters are present on the Discrepancies page."
        
        # --> Verify the view loads without requiring a file upload or sync action
        # Assert: The 'De' (start) date input is populated with 2026-07-07.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/div/div[2]/div[1]/input").nth(0)).to_have_value("2026-07-07", timeout=15000), "The 'De' (start) date input is populated with 2026-07-07."
        # Assert: The 'Até' (end) date input is populated with 2026-08-06.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/div/div[2]/div[2]/input").nth(0)).to_have_value("2026-08-06", timeout=15000), "The 'At\u00e9' (end) date input is populated with 2026-08-06."
        await page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/div/div[2]/div[3]/select").nth(0).scroll_into_view_if_needed()
        # Assert: The Status dropdown with options Abertas/Resolvidas/Ignoradas/Todas is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/div/div[2]/div[3]/select").nth(0)).to_be_visible(timeout=15000), "The Status dropdown with options Abertas/Resolvidas/Ignoradas/Todas is visible."
        await page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/table/thead/tr").nth(0).scroll_into_view_if_needed()
        # Assert: The discrepancies table header is present, showing the expected columns.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/table/thead/tr").nth(0)).to_be_visible(timeout=15000), "The discrepancies table header is present, showing the expected columns."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    