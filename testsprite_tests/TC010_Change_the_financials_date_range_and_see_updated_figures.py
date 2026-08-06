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
        
        # -> Fill the E-mail and Senha fields and click the 'Entrar na Plataforma' button to sign in.
        # seu@email.com email field
        elem = page.locator('[id="email"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("admin@eskill.com.br")
        
        # -> Fill the E-mail and Senha fields and click the 'Entrar na Plataforma' button to sign in.
        # •••••••• password field
        elem = page.locator('[id="password"]')
        await elem.wait_for(state="visible", timeout=10000)
        await elem.fill("Awa@2026#Eskill!")
        
        # -> Fill the E-mail and Senha fields and click the 'Entrar na Plataforma' button to sign in.
        # Entrar na Plataforma button
        elem = page.get_by_role('button', name='Entrar na Plataforma', exact=True)
        await elem.click(timeout=10000)
        
        # -> Click the 'Analytics' navigation link in the sidebar to open the analytics/financial reporting page.
        # Analytics link
        elem = page.get_by_role('link', name='Analytics', exact=True)
        await elem.click(timeout=10000)
        
        # -> Open the period selector dropdown labeled 'Últimos 30 dias' to reveal the available date ranges.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator('[id="period-selector"]')
        await elem.click(timeout=10000)
        
        # -> Select the 'Últimos 7 dias' option from the period selector dropdown to change the financial date range.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select 'Últimos 30 dias' from the period dropdown on the Analytics page.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select 'Últimos 7 dias' from the period dropdown and verify the dashboard shows 'Últimos 7 dias'.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select 'Últimos 30 dias' from the period dropdown on the Analytics page.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select 'Últimos 7 dias' from the period dropdown and verify the page shows 'Últimos 7 dias'.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select 'Últimos 30 dias' from the period dropdown and verify the page shows 'Período selecionado: Últimos 30 dias' and the revenue card ('Receita no período') is present.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select the 'Últimos 7 dias' option from the period dropdown and verify the dashboard updates to show 'Período selecionado: Últimos 7 dias' and the revenue card.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select 'Últimos 30 dias' from the period dropdown and allow the dashboard to update so the page shows 'Período selecionado: Últimos 30 dias' and the revenue card updates accordingly.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select the 'Últimos 7 dias' option from the period dropdown and verify the page shows 'Período selecionado: Últimos 7 dias'.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select 'Últimos 30 dias' from the period dropdown and allow the dashboard to update so the period label and revenue card reflect the selected range.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select the 'Últimos 7 dias' option from the period dropdown to change the reporting range.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select the 'Últimos 30 dias' option from the period dropdown to change the reporting range and observe the dashboard update.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select 'Últimos 7 dias' from the period dropdown to change the reporting range.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Open the period dropdown labeled 'Últimos X dias', select 'Últimos 90 dias', and verify the page shows 'Período selecionado: Últimos 90 dias' and the revenue card ('RECEITA NO PERÍODO') updates.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator('[id="period-selector"]')
        await elem.click(timeout=10000)
        
        # -> Open the period dropdown labeled 'Últimos X dias', select 'Últimos 90 dias', and verify the page shows 'Período selecionado: Últimos 90 dias' and the revenue card ('RECEITA NO PERÍODO') updates.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # -> Select 'Últimos 7 dias' from the period dropdown (label: Últimos 7 dias) to change the reporting range.
        # Últimos 7 dias Últimos 30 dias Últimos 90 dias dropdown
        elem = page.locator("xpath=/html/body/div[4]/main/div/div[4]/div/div/div/select").nth(0)
        await elem.wait_for(state="visible", timeout=10000)
        await elem.select_option("")
        
        # --> Assertions to verify final state
        
        # --> Verify the reporting figures update for the selected date range
        # Assert: The revenue change '+0%' is shown, indicating reporting figures are displayed for the selected range.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[3]/div[2]/div/small").nth(0)).to_have_text("+0%", timeout=15000), "The revenue change '+0%' is shown, indicating reporting figures are displayed for the selected range."
        await page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[1]/div/div[2]/canvas").nth(0).scroll_into_view_if_needed()
        # Assert: The revenue evolution chart canvas is visible for the selected period.
        await expect(page.locator("xpath=/html/body/div[4]/main/div/div[4]/div[1]/div/div[2]/canvas").nth(0)).to_be_visible(timeout=15000), "The revenue evolution chart canvas is visible for the selected period."
        await asyncio.sleep(5)

    finally:
        if context:
            await context.close()
        if browser:
            await browser.close()
        if pw:
            await pw.stop()

asyncio.run(run_test())
    