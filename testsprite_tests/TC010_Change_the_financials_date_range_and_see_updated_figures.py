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
        
        # -> Click the 'Entrar na Plataforma' button after filling the E-mail and Senha fields to log into the staging app.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Click the 'Entrar na Plataforma' button after filling the E-mail and Senha fields to log into the staging app.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Click the 'Entrar na Plataforma' button after filling the E-mail and Senha fields to log into the staging app.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Analytics' navigation item in the left sidebar to open the reporting area.
        # Analytics link
        elem = page.get_by_role('link', name='Analytics', exact=True)
        await elem.click(timeout=10000)
        
        # -> Open the period dropdown (currently showing 'Últimos 30 dias') so the 'Últimos 7 dias' option can be selected.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator('[id="period-selector"]')
        await elem.click(timeout=10000)
        
        # -> Select 'Últimos 7 dias' from the period selector dropdown and confirm the page shows the new period.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # --> Assertions to verify final state
        
        # --> Verify the reporting figures update for the selected date range
        # Assert: The period selector contains 'Últimos 7 dias', confirming the selected date range.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[1]/div/div[1]/select").nth(0)).to_contain_text("\u00daltimos 7 dias", timeout=15000), "The period selector contains '\u00daltimos 7 dias', confirming the selected date range."
        # Assert: The revenue change percentage is shown as +0% for the selected period.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[2]/div/small").nth(0)).to_have_text("+0%", timeout=15000), "The revenue change percentage is shown as +0% for the selected period."
        await page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[1]/div/div[2]/canvas").nth(0).scroll_into_view_if_needed()
        # Assert: The revenue evolution chart is visible for the selected date range.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[1]/div/div[2]/canvas").nth(0)).to_be_visible(timeout=15000), "The revenue evolution chart is visible for the selected date range."
        await page.locator("xpath=/html/body/div[4]/main/div/div[5]/div[1]/div/div/canvas").nth(0).scroll_into_view_if_needed()
        # Assert: The forecast/secondary chart is visible for the selected date range.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[5]/div[1]/div/div/canvas").nth(0)).to_be_visible(timeout=15000), "The forecast/secondary chart is visible for the selected date range."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    