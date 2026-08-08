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
        
        # -> Fill the E-mail field with 'admin@eskill.com.br', fill the Senha field with the provided password, then click the 'Entrar na Plataforma' button.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the E-mail field with 'admin@eskill.com.br', fill the Senha field with the provided password, then click the 'Entrar na Plataforma' button.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the E-mail field with 'admin@eskill.com.br', fill the Senha field with the provided password, then click the 'Entrar na Plataforma' button.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Scroll the dashboard so the sidebar reveals the 'Financeiro' (Financials) link.
        await page.mouse.wheel(0, 300)
        
        # -> Scroll the sidebar to reveal the 'Financeiro' link in the navigation.
        await page.mouse.wheel(0, 300)
        
        # -> Click the 'Relatórios' link under the 'Financeiro' heading in the sidebar to open the financial reports view.
        # Relatórios link
        elem = page.locator('a[href="/dashboard/financials"]')
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify PnL information is displayed
        # Assert: The PnL label 'Faturamento' is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Faturamento", timeout=15000), "The PnL label 'Faturamento' is visible."
        # Assert: The PnL label 'Receita Líquida' is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Receita L\u00edquida", timeout=15000), "The PnL label 'Receita L\u00edquida' is visible."
        # Assert: The PnL label 'Lucro Bruto' is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Lucro Bruto", timeout=15000), "The PnL label 'Lucro Bruto' is visible."
        # Assert: The PnL label 'Número de Vendas' is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("N\u00famero de Vendas", timeout=15000), "The PnL label 'N\u00famero de Vendas' is visible."
        
        # --> Verify financial summary widgets are visible
        # Assert: The Caixa Mercado Pago financial summary widget is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Caixa Mercado Pago", timeout=15000), "The Caixa Mercado Pago financial summary widget is visible."
        # Assert: The Caixa do Ledger (livro financeiro) financial summary widget is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_contain_text("Caixa do Ledger (livro financeiro)", timeout=15000), "The Caixa do Ledger (livro financeiro) financial summary widget is visible."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    