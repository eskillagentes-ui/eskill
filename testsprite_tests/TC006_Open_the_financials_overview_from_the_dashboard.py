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
        
        # -> Fill the email and password fields and submit the login form by clicking the 'Entrar na Plataforma' button.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the email and password fields and submit the login form by clicking the 'Entrar na Plataforma' button.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the email and password fields and submit the login form by clicking the 'Entrar na Plataforma' button.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Type 'Financeiro' into the sidebar search box labeled 'Buscar...' and wait for the search suggestions to appear.
        # Buscar... text field
        elem = page.locator('[id="sidebarSearch"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Financeiro")
        
        # -> Click the 'Financeiro' sidebar menu item (visible in the left navigation) to open the Financials page.
        # ⌘K
        elem = page.locator('xpath=/html/body/aside/div[3]')
        await elem.click(timeout=10000)
        
        # -> Open the 'Financeiro' (Financials) page by navigating to /dashboard/financials and verify the financial summary widgets.
        await page.goto("http://localhost:8877/dashboard/financials")
        try:
            await page.wait_for_load_state("domcontentloaded", timeout=5000)
        except Exception:
            pass
        
        # --> Assertions to verify final state
        
        # --> Verify financial summary widgets are displayed
        # Assert: Financials header 'Demonstrativo de Resultados e Análise de Lucratividade' is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Demonstrativo de Resultados e An\u00e1lise de Lucratividade", timeout=15000), "Financials header 'Demonstrativo de Resultados e An\u00e1lise de Lucratividade' is visible."
        # Assert: Settlement widget 'Caixa Mercado Pago' is visible on the Financials page.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Caixa Mercado Pago", timeout=15000), "Settlement widget 'Caixa Mercado Pago' is visible on the Financials page."
        # Assert: PnL metric 'Faturamento' is visible in the financial summary widgets.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Faturamento", timeout=15000), "PnL metric 'Faturamento' is visible in the financial summary widgets."
        
        # --> Verify PnL and settlements information is displayed
        # Assert: Settlement widget 'Caixa Mercado Pago' is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Caixa Mercado Pago", timeout=15000), "Settlement widget 'Caixa Mercado Pago' is visible."
        # Assert: PnL metric 'Faturamento' is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Faturamento", timeout=15000), "PnL metric 'Faturamento' is visible."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    