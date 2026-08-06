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
        
        # -> Fill the E-mail field with admin@eskill.com.br, the Senha field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to submit the login form.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the E-mail field with admin@eskill.com.br, the Senha field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to submit the login form.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the E-mail field with admin@eskill.com.br, the Senha field with Awa@2026#Eskill!, then click the 'Entrar na Plataforma' button to submit the login form.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Raio X da Conta' link in the left sidebar to open Account Health.
        # Raio X da Conta X link
        elem = page.get_by_role('link', name='Raio X da Conta X', exact=True)
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify an empty state message is displayed
        # Assert: The empty-state CTA 'Conectar conta' is present.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div[2]/div/div/div/a").nth(0)).to_have_text("Conectar conta", timeout=15000), "The empty-state CTA 'Conectar conta' is present."
        await page.locator("xpath=/html/body/div[4]/main/div/div[2]/div[2]/div/h6/i").nth(0).scroll_into_view_if_needed()
        # Assert: The empty-state icon is visible, indicating the empty state card is shown.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div[2]/div/h6/i").nth(0)).to_be_visible(timeout=15000), "The empty-state icon is visible, indicating the empty state card is shown."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    