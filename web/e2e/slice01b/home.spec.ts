import { expect, test } from '@playwright/test';
import { expectAccessible, expectNoSidewaysScroll, rgb } from '../support/checks';

/*
 * Slice 01b — the home page uses the showcase hero and stays complete.
 */

test('the home page opens with the showcase hero [NFR-UX-02]', async ({ page }) => {
  await page.goto('/');

  const hero = page.getByTestId('home-hero');
  await expect(hero).toBeVisible();
  expect(await hero.evaluate((element) => getComputedStyle(element).backgroundImage)).toContain(rgb('#A8349A'));

  const title = page.getByRole('heading', { level: 1, name: 'New Voting System' });
  await expect(title).toBeVisible();
  expect(await hero.evaluate((element, heading) => element.contains(heading), await title.elementHandle())).toBe(true);
  expect(await title.evaluate((element) => getComputedStyle(element).color)).toBe(rgb('#FFFFFF'));
});

test('the status is all there once the page has settled [NFR-OPS-01]', async ({ page }) => {
  await page.goto('/');

  for (const testId of ['status-api', 'status-database', 'status-redis']) {
    const row = page.getByTestId(testId);
    await expect(row).toHaveAttribute('data-state', 'ok');
    await expect.poll(() => row.evaluate((element) => {
      let node: Element | null = element;
      let opacity = 1;
      while (node) { opacity *= parseFloat(getComputedStyle(node).opacity); node = node.parentElement; }
      return opacity;
    }), { timeout: 3000 }).toBe(1);
  }
  await expect(page.getByTestId('status-time')).toBeVisible();
});

test('the home page is complete with "reduce motion" and stays accessible [NFR-UX-03]', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ reducedMotion: 'reduce', baseURL, locale: 'fr-FR' });
  const page = await context.newPage();

  await page.goto('/');

  expect(await page.evaluate(() => document.getAnimations().filter((a) => a.playState === 'running').length)).toBe(0);
  await expect(page.getByTestId('status-database')).toBeVisible();
  await expectNoSidewaysScroll(page);
  await expectAccessible(page);
  await context.close();
});
