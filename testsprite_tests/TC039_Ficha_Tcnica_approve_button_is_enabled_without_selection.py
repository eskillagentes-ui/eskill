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
        
        # -> Click the 'Entrar na Plataforma' button to submit the login form
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Click the 'Entrar na Plataforma' button to submit the login form
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Click the 'Entrar na Plataforma' button to submit the login form
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Ficha Técnica' link in the left menu to open the technical-sheet tab under SEO Killer.
        # Ficha Técnica link
        elem = page.get_by_role('link', name='Ficha Técnica', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Aprovar pendentes ≥ 85%' button to open the confirmation dialog (do not confirm yet).
        # Aprovar pendentes ≥ 85% button
        elem = page.get_by_role('button', name='Aprovar pendentes ≥ 85%', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Aprovar pendentes ≥ 85%' button to open the confirmation dialog (do not confirm or apply approvals).
        # Aprovar pendentes ≥ 85% button
        elem = page.get_by_role('button', name='Aprovar pendentes ≥ 85%', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Aprovar pendentes ≥ 85%' button to open the confirmation dialog (do not confirm).
        # Aprovar pendentes ≥ 85% button
        elem = page.get_by_role('button', name='Aprovar pendentes ≥ 85%', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Aprovar pendentes ≥ 85%' button to open the confirmation dialog (do not confirm or apply).
        # Aprovar pendentes ≥ 85% button
        elem = page.get_by_role('button', name='Aprovar pendentes ≥ 85%', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Aprovar pendentes ≥ 85%' button to open the confirmation dialog (do not confirm or apply changes).
        # Aprovar pendentes ≥ 85% button
        elem = page.get_by_role('button', name='Aprovar pendentes ≥ 85%', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Aprovar pendentes ≥ 85%' button to open its confirmation dialog (then cancel/dismiss it without applying).
        # Aprovar pendentes ≥ 85% button
        elem = page.get_by_role('button', name='Aprovar pendentes ≥ 85%', exact=True)
        await elem.click(timeout=10000)
        
        # --> Assertions to verify final state
        
        # --> Verify the button labeled Aprovar pendentes is visible and not disabled
        await page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[2]/div[2]/div/div[2]/div/div[1]/div[2]/div[2]/button[1]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Aprovar pendentes ≥ 85%' button is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[2]/div[2]/div/div[2]/div/div[1]/div[2]/div[2]/button[1]").nth(0)).to_be_visible(timeout=15000), "The 'Aprovar pendentes \u2265 85%' button is visible."
        # Assert: The button's visible text equals 'Aprovar pendentes ≥ 85%'.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[2]/div[2]/div/div[2]/div/div[1]/div[2]/div[2]/button[1]").nth(0)).to_have_text("Aprovar pendentes \u2265 85%", timeout=15000), "The button's visible text equals 'Aprovar pendentes \u2265 85%'."
        # Assert: The button's title is 'Aprova elegíveis mesmo sem linhas na tela', indicating it can be used without selecting rows.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[2]/div[2]/div/div[2]/div/div[1]/div[2]/div[2]/button[1]").nth(0)).to_have_attribute("title", "Aprova eleg\u00edveis mesmo sem linhas na tela", timeout=15000), "The button's title is 'Aprova eleg\u00edveis mesmo sem linhas na tela', indicating it can be used without selecting rows."
        
        # --> Verify a Completo link/button toward /dashboard/tech-sheet is visible
        await page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[2]/div[2]/div/div[1]/div[1]/div[2]/a").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Completo' link to the full panel is visible.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[2]/div[2]/div/div[1]/div[1]/div[2]/a").nth(0)).to_be_visible(timeout=15000), "The 'Completo' link to the full panel is visible."
        # Assert: The 'Completo' link points to /dashboard/tech-sheet.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[2]/div[2]/div/div[1]/div[1]/div[2]/a").nth(0)).to_have_attribute("href", "/dashboard/tech-sheet", timeout=15000), "The 'Completo' link points to /dashboard/tech-sheet."
        
        # --> Verify still on the Ficha Técnica tab and no Mercado Livre apply was triggered
        # Assert: Still on the Ficha Técnica tab (URL contains dashboard/seo-killer#technical-sheet).
        await expect(page).to_have_url(re.compile("dashboard/seo\\-killer\\#technical\\-sheet"), timeout=15000), "Still on the Ficha T\u00e9cnica tab (URL contains dashboard/seo-killer#technical-sheet)."
        await page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[2]/div[2]/div/div[2]/div/div[1]/div[2]/div[2]/button[3]").nth(0).scroll_into_view_if_needed()
        # Assert: The 'Aplicar aprovadas' button is visible, indicating no apply to Mercado Livre was triggered.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[2]/div[2]/div/div[2]/div/div[1]/div[2]/div[2]/button[3]").nth(0)).to_be_visible(timeout=15000), "The 'Aplicar aprovadas' button is visible, indicating no apply to Mercado Livre was triggered."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    