import { expect, test } from '@playwright/test';
import { expectAccessible, expectNoSidewaysScroll, recordForeignRequests, rgb } from '../support/checks';

/*
 * Slice 01 — the home page of the walking skeleton.
 * It proves the whole chain: browser → proxy → web → API → MariaDB.
 */

test.describe('home page', () => {
  test('shows the product name and a system that is up [NFR-OPS-01]', async ({ page }) => {
    await page.goto('/');

    await expect(page.getByRole('heading', { level: 1, name: 'New Voting System' })).toBeVisible();
    await expect(page.getByTestId('status-api')).toHaveAttribute('data-state', 'ok');
    await expect(page.getByTestId('status-database')).toHaveAttribute('data-state', 'ok');
    await expect(page.getByTestId('status-redis')).toHaveAttribute('data-state', 'ok');
  });

  test('shows a time read from the database [NFR-OPS-01]', async ({ page }) => {
    await page.goto('/');

    const time = page.getByTestId('status-time');
    await expect(time).toBeVisible();

    // A <time> element whose datetime attribute is the value the API read from MariaDB.
    expect(await time.evaluate((element) => element.tagName)).toBe('TIME');
    const value = await time.getAttribute('datetime');
    expect(value).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
    expect(Math.abs(Date.now() - Date.parse(value!))).toBeLessThan(2 * 60 * 1000);
  });

  test('is in French by default [NFR-UX-01]', async ({ page }) => {
    await page.goto('/');

    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    await expect(page.getByRole('heading', { level: 2, name: 'État du système' })).toBeVisible();
    await expect(page.getByTestId('status-database')).toContainText('Base de données');
    await expect(page.getByTestId('status-database')).toContainText('En ligne');
  });

  test('loads nothing from another origin [NFR-PERF-01]', async ({ page, baseURL }) => {
    const foreign = recordForeignRequests(page, baseURL!);

    await page.goto('/');
    await page.waitForLoadState('networkidle');

    expect(foreign).toEqual([]);
  });

  test('uses the two fonts of the design [NFR-UX-02]', async ({ page }) => {
    await page.goto('/');
    await page.evaluate(() => document.fonts.ready);

    const heading = await page
      .getByRole('heading', { level: 1 })
      .evaluate((element) => getComputedStyle(element).fontFamily);
    const body = await page.evaluate(() => getComputedStyle(document.body).fontFamily);

    expect(heading).toMatch(/Bricolage/i);
    expect(body).toMatch(/Public.?Sans/i);
  });

  test('exposes the design tokens as CSS variables [NFR-UX-02]', async ({ page }) => {
    await page.goto('/');

    // The value is read as the browser resolves it, so "#fff" and "#FFFFFF" are the same.
    const token = (name: string) =>
      page.evaluate((n) => {
        const probe = document.createElement('span');
        probe.style.color = `var(${n})`;
        document.body.appendChild(probe);
        const resolved = getComputedStyle(probe).color;
        probe.remove();
        return getComputedStyle(document.documentElement).getPropertyValue(n).trim() === '' ? '' : resolved;
      }, name);

    expect(await token('--color-ink')).toBe(rgb('#111B33'));
    expect(await token('--color-ink-soft')).toBe(rgb('#4A556B'));
    expect(await token('--color-canvas')).toBe(rgb('#F3F5F9'));
    expect(await token('--color-surface')).toBe(rgb('#FFFFFF'));
    expect(await token('--color-line')).toBe(rgb('#D8DEE9'));
    expect(await token('--color-primary')).toBe(rgb('#1E3A8A'));
    expect(await token('--color-primary-hover')).toBe(rgb('#172B66'));
    expect(await token('--color-primary-soft')).toBe(rgb('#E6ECFA'));
    expect(await token('--color-warm')).toBe(rgb('#C2410C'));
    expect(await token('--color-teal')).toBe(rgb('#0F766E'));
    expect(await token('--color-danger')).toBe(rgb('#9A2A0A'));
  });

  test('fits the screen without scrolling sideways [NFR-UX-02]', async ({ page }) => {
    await page.goto('/');

    await expectNoSidewaysScroll(page);
  });

  test('passes the automated accessibility check [NFR-UX-03]', async ({ page }) => {
    await page.goto('/');

    await expectAccessible(page);
  });
});

test.describe('home page, browser set to English', () => {
  test.use({ locale: 'en-US' });

  test('is in English [NFR-UX-01]', async ({ page }) => {
    await page.goto('/');

    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('heading', { level: 2, name: 'System status' })).toBeVisible();
    await expect(page.getByTestId('status-database')).toContainText('Database');
    await expect(page.getByTestId('status-database')).toContainText('Online');
  });

  test('a chosen language wins over the browser language [NFR-UX-01]', async ({ page, context, baseURL }) => {
    await context.addCookies([{ name: 'locale', value: 'fr', url: baseURL! }]);

    await page.goto('/');

    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    await expect(page.getByRole('heading', { level: 2, name: 'État du système' })).toBeVisible();
  });
});
