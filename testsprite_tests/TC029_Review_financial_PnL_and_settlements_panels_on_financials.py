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
        
        # -> Fill 'admin@eskill.com.br' into the E-mail field, fill 'Awa@2026#Eskill!' into the Senha field, then click the 'Entrar na Plataforma' button.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill 'admin@eskill.com.br' into the E-mail field, fill 'Awa@2026#Eskill!' into the Senha field, then click the 'Entrar na Plataforma' button.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill 'admin@eskill.com.br' into the E-mail field, fill 'Awa@2026#Eskill!' into the Senha field, then click the 'Entrar na Plataforma' button.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Conciliação' link in the sidebar under the 'FINANCEIRO' section to open the Financials > Conciliação page.
        # Conciliação link
        elem = page.get_by_role('link', name='Conciliação', exact=True)
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify PnL or revenue/summary widgets are visible in the financials main content area
        await page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[1]/div/div/div/div").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Conciliados' summary widget is visible in the financials main content area.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[1]/div/div/div/div").nth(0)).to_be_visible(timeout=15000), "The 'Conciliados' summary widget is visible in the financials main content area."
        await page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[2]/div/div/div/div").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Pendentes' summary widget is visible in the financials main content area.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[2]/div/div/div/div").nth(0)).to_be_visible(timeout=15000), "The 'Pendentes' summary widget is visible in the financials main content area."
        await page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[3]/div/div/div/div").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Divergentes' summary widget is visible in the financials main content area.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[3]/div/div/div/div").nth(0)).to_be_visible(timeout=15000), "The 'Divergentes' summary widget is visible in the financials main content area."
        await page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[4]/div/div/div/div").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Total Confirmado' summary widget is visible in the financials main content area.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[4]/div/div/div/div").nth(0)).to_be_visible(timeout=15000), "The 'Total Confirmado' summary widget is visible in the financials main content area."
        
        # --> Verify settlements, fees, or cash-related panels are present in the financials layout even if values are zero
        # Assert: The 'Conciliados' summary card is visible and shows 0.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[1]/div/div/div/div").nth(0)).to_contain_text("Conciliados 0", timeout=15000), "The 'Conciliados' summary card is visible and shows 0."
        # Assert: The 'Pendentes' summary card is visible and shows 0.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[2]/div/div/div/div").nth(0)).to_contain_text("Pendentes 0", timeout=15000), "The 'Pendentes' summary card is visible and shows 0."
        # Assert: The 'Total Confirmado' widget is visible and shows R$ 0,00.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[4]/div/div/div/div").nth(0)).to_contain_text("Total Confirmado R$ 0,00", timeout=15000), "The 'Total Confirmado' widget is visible and shows R$ 0,00."
        # Assert: The settlements table displays the empty-state message prompting import of a report.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[2]/table/tbody/tr/td").nth(0)).to_have_text("Nenhum registro encontrado. Importe um relat\u00f3rio.", timeout=15000), "The settlements table displays the empty-state message prompting import of a report."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    