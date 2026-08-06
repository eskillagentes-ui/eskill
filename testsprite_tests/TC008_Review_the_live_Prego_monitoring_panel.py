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
        
        # -> Fill the 'E-mail' and 'Senha' fields and click the 'Entrar na Plataforma' button to log in.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the 'E-mail' and 'Senha' fields and click the 'Entrar na Plataforma' button to log in.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the 'E-mail' and 'Senha' fields and click the 'Entrar na Plataforma' button to log in.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Pregão' navigation item in the left sidebar to open the Pregão monitoring page.
        # Pregão Live link
        elem = page.get_by_role('link', name='Pregão Live', exact=True)
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the live Pregão snapshot is displayed
        # Assert: The browser is on the Pregão page (URL contains /dashboard/pregao).
        await expect(page).to_have_url(re.compile("/dashboard/pregao"), timeout=15000), "The browser is on the Preg\u00e3o page (URL contains /dashboard/pregao)."
        await page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0).scroll_into_view_if_needed()
        # Assert: The main Pregão snapshot container is visible on the page.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[1]").nth(0)).to_be_visible(timeout=15000), "The main Preg\u00e3o snapshot container is visible on the page."
        # Assert: The page breadcrumb reads 'Pregao', confirming the Pregão snapshot is displayed.
        await expect(page.locator("xpath=/html/body/div[4]/main/header/div[1]/nav/span[3]").nth(0)).to_have_text("Pregao", timeout=15000), "The page breadcrumb reads 'Pregao', confirming the Preg\u00e3o snapshot is displayed."
        
        # --> Verify the event feed and charts are displayed
        await page.locator("xpath=/html/body/div[4]/main/div/div[2]/header/div[1]/div").nth(0).scroll_into_view_if_needed()
        # Assert: The main Pregão chart header is visible, confirming the snapshot/chart container is present.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/header/div[1]/div").nth(0)).to_be_visible(timeout=15000), "The main Preg\u00e3o chart header is visible, confirming the snapshot/chart container is present."
        await page.locator("xpath=/html/body/div[4]/main/div/div[2]/div[2]/div[1]/div[2]/a[1]").nth(0).scroll_into_view_if_needed()
        # Assert: A metrics/chart card (TACOS) is visible, confirming the charts/metrics area is displayed.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[2]/div[2]/div[1]/div[2]/a[1]").nth(0)).to_be_visible(timeout=15000), "A metrics/chart card (TACOS) is visible, confirming the charts/metrics area is displayed."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    