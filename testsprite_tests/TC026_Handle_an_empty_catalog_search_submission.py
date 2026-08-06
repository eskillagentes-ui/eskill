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
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br, fill the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to submit the login form.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br, fill the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to submit the login form.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br, fill the 'Senha' field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to submit the login form.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Meus Anúncios' link in the sidebar to open the catalog page.
        # Meus Anúncios link
        elem = page.get_by_role('link', name='Meus Anúncios', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Filtrar' button to submit the empty catalog search and verify an empty-state or validation message is shown.
        # Filtrar button
        elem = page.get_by_role('button', name='Filtrar', exact=True)
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify a search validation or empty-state message is visible
        # Assert: The page shows the empty-state message 'Nenhum anúncio encontrado' in the results area.
        await expect(page.locator("xpath=/html/body/div[4]/main/div[4]/div[1]/table/tbody/tr/td").nth(0)).to_have_text("Nenhum an\u00fancio encontrado", timeout=15000), "The page shows the empty-state message 'Nenhum an\u00fancio encontrado' in the results area."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    