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
        
        # -> Fill the email and password fields and click the 'Entrar na Plataforma' button to submit the login form.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the email and password fields and click the 'Entrar na Plataforma' button to submit the login form.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the email and password fields and click the 'Entrar na Plataforma' button to submit the login form.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify dashboard metrics cards are visible
        await page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[1]/div[1]/div").nth(0).scroll_into_view_if_needed()
        # Assert: Dashboard metric card (card 1) is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[1]/div[1]/div").nth(0)).to_be_visible(timeout=15000), "Dashboard metric card (card 1) is visible."
        await page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[2]/div[1]/div").nth(0).scroll_into_view_if_needed()
        # Assert: Dashboard metric card (card 2) is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[2]/div[1]/div").nth(0)).to_be_visible(timeout=15000), "Dashboard metric card (card 2) is visible."
        await page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[3]/div[1]/div").nth(0).scroll_into_view_if_needed()
        # Assert: Dashboard metric card (card 3) is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[3]/div[1]/div").nth(0)).to_be_visible(timeout=15000), "Dashboard metric card (card 3) is visible."
        await page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[4]/div[1]/div").nth(0).scroll_into_view_if_needed()
        # Assert: Dashboard metric card (card 4) is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[4]/div[1]/div").nth(0)).to_be_visible(timeout=15000), "Dashboard metric card (card 4) is visible."
        
        # --> Verify the main module navigation is visible
        await page.locator("xpath=/html/body/aside/nav/div[1]/a[1]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Dashboard' navigation item is visible in the main module navigation.
        await expect(page.locator("xpath=/html/body/aside/nav/div[1]/a[1]").nth(0)).to_be_visible(timeout=15000), "The 'Dashboard' navigation item is visible in the main module navigation."
        await page.locator("xpath=/html/body/aside/nav/div[1]/a[2]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Analytics' navigation item is visible in the main module navigation.
        await expect(page.locator("xpath=/html/body/aside/nav/div[1]/a[2]").nth(0)).to_be_visible(timeout=15000), "The 'Analytics' navigation item is visible in the main module navigation."
        await page.locator("xpath=/html/body/aside/nav/div[1]/a[3]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Diagnóstico' navigation item is visible in the main module navigation.
        await expect(page.locator("xpath=/html/body/aside/nav/div[1]/a[3]").nth(0)).to_be_visible(timeout=15000), "The 'Diagn\u00f3stico' navigation item is visible in the main module navigation."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    