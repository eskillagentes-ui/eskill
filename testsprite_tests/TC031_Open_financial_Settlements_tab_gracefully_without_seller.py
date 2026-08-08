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
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br and the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br and the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br and the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Scroll down the dashboard/sidebar to reveal the 'Financials' link in the sidebar so it can be clicked.
        await page.mouse.wheel(0, 300)
        
        # -> Reveal the 'Financials' link in the dashboard sidebar by scrolling the page and searching for the label 'Financials'.
        await page.mouse.wheel(0, 300)
        
        # -> Open the Financials page (Dashboard → Financials) by navigating to the Financials URL and load the page.
        await page.goto("http://localhost:8877/dashboard/financials")
        try:
            await page.wait_for_load_state("domcontentloaded", timeout=5000)
        except Exception:
            pass
        
        # -> Scroll the Financials page content down to reveal the tab area so the 'Settlements' tab button becomes visible.
        await page.mouse.wheel(0, 300)
        
        # -> Click the 'Liquidações MP' tab button (visible label: 'Liquidações MP') to open the Settlements panel.
        # Liquidações MP button
        elem = page.locator('[id="tab-settlements-btn"]')
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the Settlements tab panel is visible with a table, empty-state, or graceful message — not a blank crash or PHP error page
        await page.locator("xpath=/html/body/div[4]/main/div/ul/li[7]/button").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Liquidações MP' (Settlements) tab button is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/ul/li[7]/button").nth(0)).to_be_visible(timeout=15000), "The 'Liquida\u00e7\u00f5es MP' (Settlements) tab button is visible."
        # Assert: A graceful message 'Seller ID não encontrado' is visible in the Settlements panel.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Seller ID n\u00e3o encontrado", timeout=15000), "A graceful message 'Seller ID n\u00e3o encontrado' is visible in the Settlements panel."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    