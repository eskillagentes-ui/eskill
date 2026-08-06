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
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br, fill the 'Senha' field with the provided password, then click the 'Entrar na Plataforma' button.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br, fill the 'Senha' field with the provided password, then click the 'Entrar na Plataforma' button.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the 'E-mail' field with admin@eskill.com.br, fill the 'Senha' field with the provided password, then click the 'Entrar na Plataforma' button.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the authenticated dashboard shell is displayed
        # Assert: The browser is on the dashboard URL (/dashboard).
        await expect(page).to_have_url(re.compile("/dashboard"), timeout=15000), "The browser is on the dashboard URL (/dashboard)."
        await page.locator("xpath=/html/body/aside/nav/div[1]/a[1]").nth(0).scroll_into_view_if_needed()
        # Assert: Left navigation displays the 'Dashboard' link.
        await expect(page.locator("xpath=/html/body/aside/nav/div[1]/a[1]").nth(0)).to_be_visible(timeout=15000), "Left navigation displays the 'Dashboard' link."
        await page.locator("xpath=/html/body/aside/nav/div[1]/a[2]").nth(0).scroll_into_view_if_needed()
        # Assert: Left navigation displays the 'Analytics' link.
        await expect(page.locator("xpath=/html/body/aside/nav/div[1]/a[2]").nth(0)).to_be_visible(timeout=15000), "Left navigation displays the 'Analytics' link."
        await page.locator("xpath=/html/body/div[4]/main/header/div[2]/div[2]/button").nth(0).scroll_into_view_if_needed()
        # Assert: Header shows the authenticated user's name 'Admin eSkill'.
        await expect(page.locator("xpath=/html/body/div[4]/main/header/div[2]/div[2]/button").nth(0)).to_be_visible(timeout=15000), "Header shows the authenticated user's name 'Admin eSkill'."
        
        # --> Verify dashboard metrics and module navigation are visible
        await page.locator("xpath=/html/body/aside/nav/div[1]/a[1]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Dashboard' navigation link is visible.
        await expect(page.locator("xpath=/html/body/aside/nav/div[1]/a[1]").nth(0)).to_be_visible(timeout=15000), "The 'Dashboard' navigation link is visible."
        await page.locator("xpath=/html/body/aside/nav/div[1]/a[2]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Analytics' navigation link is visible.
        await expect(page.locator("xpath=/html/body/aside/nav/div[1]/a[2]").nth(0)).to_be_visible(timeout=15000), "The 'Analytics' navigation link is visible."
        await page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[1]/div[1]/div").nth(0).scroll_into_view_if_needed()
        # Assert: A dashboard metric card is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[1]/div[1]/div").nth(0)).to_be_visible(timeout=15000), "A dashboard metric card is visible."
        await page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[2]/div[1]/div").nth(0).scroll_into_view_if_needed()
        # Assert: Another dashboard metric card is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[2]/div[1]/div").nth(0)).to_be_visible(timeout=15000), "Another dashboard metric card is visible."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    