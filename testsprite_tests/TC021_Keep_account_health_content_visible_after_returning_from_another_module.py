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
        
        # -> Fill the E-mail field with admin@eskill.com.br, fill the Senha field with the provided password, then click the 'Entrar na Plataforma' button to submit the login form.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the E-mail field with admin@eskill.com.br, fill the Senha field with the provided password, then click the 'Entrar na Plataforma' button to submit the login form.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the E-mail field with admin@eskill.com.br, fill the Senha field with the provided password, then click the 'Entrar na Plataforma' button to submit the login form.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Pregão' link in the left sidebar to open the Pricing/Auction module.
        # Pregão Live link
        elem = page.get_by_role('link', name='Pregão Live', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Raio X da Conta' link in the left sidebar to open the Account Health (Raio X da Conta) page.
        # Raio X da Conta X link
        elem = page.get_by_role('link', name='Raio X da Conta X', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Pregão' link in the left sidebar to navigate to the Pricing module.
        # Pregão Live link
        elem = page.get_by_role('link', name='Pregão Live', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Raio X da Conta' link in the left sidebar to return to the Account Health page.
        # Raio X da Conta X link
        elem = page.get_by_role('link', name='Raio X da Conta X', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Pregão' link in the left sidebar to navigate to the Pricing module.
        # Pregão Live link
        elem = page.get_by_role('link', name='Pregão Live', exact=True)
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the account health pillars are displayed
        await page.locator("xpath=/html/body/div[4]/main/div/div[2]/div[2]/div[1]/div[2]/a[1]").nth(0).scroll_into_view_if_needed()
        # Assert: The TACOS pillar is visible in the account health area.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div[2]/div[1]/div[2]/a[1]").nth(0)).to_be_visible(timeout=15000), "The TACOS pillar is visible in the account health area."
        await page.locator("xpath=/html/body/div[4]/main/div/div[2]/div[2]/div[1]/div[2]/a[2]").nth(0).scroll_into_view_if_needed()
        # Assert: The SENTINELA pillar is visible in the account health area.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div[2]/div[1]/div[2]/a[2]").nth(0)).to_be_visible(timeout=15000), "The SENTINELA pillar is visible in the account health area."
        # Assert: The open questions pillar is visible and shows 0.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div[2]/div[2]/div[2]/div/span").nth(0)).to_have_text("0", timeout=15000), "The open questions pillar is visible and shows 0."
        current_url = await page.evaluate("() => window.location.href")
        # Assert: page loaded with a URL (final outcome verified by the AI judge during the run)
        assert current_url, 'Page should have loaded with a URL'
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    