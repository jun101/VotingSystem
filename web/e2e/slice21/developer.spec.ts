import { expect, test } from '@playwright/test';
import { expectAccessible, expectNoSidewaysScroll, recordForeignRequests, rgb } from '../support/checks';

/*
 * Slice 21 — the developer page and the big site footer shared with the home page.
 */

const LINKEDIN = 'https://www.linkedin.com/in/josu%C3%A9-junior-fleuridor-b6905b157/';

test('the developer page presents the developer and links to the profiles [NFR-UX-02]', async ({ page }) => {
  await page.goto('/developer');

  await expect(page.getByRole('heading', { level: 1, name: 'Josué Junior Fleuridor' })).toBeVisible();
  await expect(page.getByTestId('developer-hero').getByRole('link', { name: 'LinkedIn' })).toHaveAttribute('href', LINKEDIN);
  await expect(page.getByTestId('developer-hero').getByRole('link', { name: 'GitHub' })).toHaveAttribute('href', 'https://github.com/jun101');
  await expect(page.getByRole('img', { name: /Josué Junior Fleuridor/ })).toBeVisible();
  await expect(page).toHaveTitle(/Le développeur/);
});

test('the developer page loads nothing from another origin and does not scroll sideways [NFR-PERF-01]', async ({ page, baseURL }) => {
  const foreign = recordForeignRequests(page, baseURL!);

  await page.goto('/developer');
  await page.waitForLoadState('networkidle');

  expect(foreign).toEqual([]);
  await expectNoSidewaysScroll(page);
});

test('the developer page is accessible, also with "reduce motion" [NFR-UX-03]', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ reducedMotion: 'reduce', baseURL, locale: 'fr-FR' });
  const page = await context.newPage();

  await page.goto('/developer');

  expect(await page.evaluate(() => document.getAnimations().filter((a) => a.playState === 'running').length)).toBe(0);
  await expectNoSidewaysScroll(page);
  await expectAccessible(page);
  await context.close();
});

test('the home page footer is near-black, carries the profiles and leads to the developer page [NFR-UX-02]', async ({ page }) => {
  await page.goto('/');

  const footer = page.locator('footer');
  expect(await footer.evaluate((element) => getComputedStyle(element).backgroundColor)).toBe(rgb('#0B0F19'));
  await expect(footer.getByRole('link', { name: 'LinkedIn' })).toHaveAttribute('href', LINKEDIN);
  await expect(footer.getByRole('link', { name: 'GitHub' })).toHaveAttribute('href', 'https://github.com/jun101');
  await expect(footer.getByRole('link', { name: 'X', exact: true })).toHaveAttribute('href', 'https://x.com/jun_101_ht');

  await page.getByTestId('footer-developer').click();
  await expect(page).toHaveURL(/\/developer$/);
});

test('the developer page has the same footer, without the system status [NFR-UX-02]', async ({ page }) => {
  await page.goto('/developer');

  await expect(page.locator('footer')).toBeVisible();
  await expect(page.getByTestId('status-api')).toHaveCount(0);
  await expect(page.getByTestId('footer-developer')).toBeVisible();
});

test('the home page links to the developer page from its menu [NFR-UX-02]', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name === 'phone', 'the section links move into the menu on a phone');
  await page.goto('/');

  await page.getByRole('navigation', { name: /Navigation principale|Main navigation/ }).getByRole('link', { name: /développeur|developer/i }).click();
  await expect(page).toHaveURL(/\/developer$/);
});
