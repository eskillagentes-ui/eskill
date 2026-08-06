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
        
        # -> Fill the 'E-mail' field with 'admin@eskill.com.br', fill the 'Senha' field with the provided password, then click the 'Entrar na Plataforma' button to submit the login form.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the 'E-mail' field with 'admin@eskill.com.br', fill the 'Senha' field with the provided password, then click the 'Entrar na Plataforma' button to submit the login form.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the 'E-mail' field with 'admin@eskill.com.br', fill the 'Senha' field with the provided password, then click the 'Entrar na Plataforma' button to submit the login form.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the dashboard home is displayed
        # Assert: The current URL contains 'dashboard', confirming the dashboard page is loaded.
        await expect(page).to_have_url(re.compile("dashboard"), timeout=15000), "The current URL contains 'dashboard', confirming the dashboard page is loaded."
        await page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/a[1]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Conectar Conta' call-to-action is visible in the dashboard hero.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/a[1]").nth(0)).to_be_visible(timeout=15000), "The 'Conectar Conta' call-to-action is visible in the dashboard hero."
        await page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/a[2]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Meus Produtos' button is visible in the dashboard hero.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/a[2]").nth(0)).to_be_visible(timeout=15000), "The 'Meus Produtos' button is visible in the dashboard hero."
        await page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/a[3]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Ver Pedidos' link is visible in the dashboard hero.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div/div/a[3]").nth(0)).to_be_visible(timeout=15000), "The 'Ver Pedidos' link is visible in the dashboard hero."
        
        # --> Verify the main module navigation is displayed
        await page.locator("xpath=/html/body/aside/nav/div[1]/a[1]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Dashboard' item in the main navigation is visible.
        await expect(page.locator("xpath=/html/body/aside/nav/div[1]/a[1]").nth(0)).to_be_visible(timeout=15000), "The 'Dashboard' item in the main navigation is visible."
        await page.locator("xpath=/html/body/aside/nav/div[1]/a[2]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Analytics' item in the main navigation is visible.
        await expect(page.locator("xpath=/html/body/aside/nav/div[1]/a[2]").nth(0)).to_be_visible(timeout=15000), "The 'Analytics' item in the main navigation is visible."
        await page.locator("xpath=/html/body/aside/nav/div[2]/a[1]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Meus Anúncios' item in the main navigation is visible.
        await expect(page.locator("xpath=/html/body/aside/nav/div[2]/a[1]").nth(0)).to_be_visible(timeout=15000), "The 'Meus An\u00fancios' item in the main navigation is visible."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    