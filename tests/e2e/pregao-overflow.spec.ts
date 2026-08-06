/**
 * Fixture visual do Pregão — overflow horizontal em 380×844.
 * Carrega CSS real via addStyleTag (evita inline gigante + hang no route.fetch).
 */
import { test, expect } from './helpers/mutation-guard';
import path from 'path';
import fs from 'fs';

const FIXTURE = path.join(__dirname, '../fixtures/pregao-overflow.html');
const CSS_PATH = path.resolve(__dirname, '../../public/css/pregao.css');

async function loadPregaoFixture(page: import('@playwright/test').Page): Promise<void> {
  let html = fs.readFileSync(FIXTURE, 'utf8');
  html = html.replace('/*__PREGAO_CSS__*/', '/* loaded via addStyleTag */');
  await page.setContent(html, { waitUntil: 'domcontentloaded' });
  // content: evita request file:// interceptado pelo safeNetwork.
  await page.addStyleTag({ content: fs.readFileSync(CSS_PATH, 'utf8') });
}

test.describe('Pregão overflow @readonly', () => {
  test('@readonly atributo hidden oculta o placeholder do gráfico', async ({ page }) => {
    await loadPregaoFixture(page);

    const chartEmpty = page.locator('#chartEmpty');
    await expect(chartEmpty).toBeHidden();
    await expect(chartEmpty).toHaveCSS('display', 'none');

    await chartEmpty.evaluate((element) => {
      (element as HTMLElement).hidden = false;
    });
    await expect(chartEmpty).toBeVisible();
    await expect(chartEmpty).toHaveCSS('display', 'flex');
  });

  test('@readonly 380×844 sem overflow horizontal', async ({ page }) => {
    await page.setViewportSize({ width: 380, height: 844 });
    await loadPregaoFixture(page);

    const metrics = await page.evaluate(() => {
      const root = document.getElementById('pregao-root');
      if (!root) return null;
      const header = root.querySelector('.pg-header') as HTMLElement | null;
      return {
        rootScroll: root.scrollWidth,
        rootClient: root.clientWidth,
        headerScroll: header ? header.scrollWidth : 0,
        headerClient: header ? header.clientWidth : 0,
        docScroll: document.documentElement.scrollWidth,
        docClient: document.documentElement.clientWidth,
      };
    });

    expect(metrics).not.toBeNull();
    expect(metrics!.rootScroll).toBeLessThanOrEqual(metrics!.rootClient);
    expect(metrics!.headerScroll).toBeLessThanOrEqual(metrics!.headerClient);
    expect(metrics!.docScroll).toBeLessThanOrEqual(metrics!.docClient);
  });
});
