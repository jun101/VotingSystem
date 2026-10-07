import { expect, test } from '@playwright/test';
import { cssOf, expectAccessible, expectNoSidewaysScroll, recordForeignRequests, rgb } from '../support/checks';

/*
 * Slice 01b — the composition pieces, shown on /dev/motion.
 * docs/design/frontend.md section 4, Composition.
 */

test.beforeEach(async ({ page }) => {
  await page.goto('/dev/motion');
});

test('hero: the three-stop gradient at 135 degrees, white text [NFR-UX-02]', async ({ page }) => {
  const background = await cssOf(page, 'demo-hero', 'background-image');

  expect(background).toContain('linear-gradient(135deg');
  expect(background).toContain(rgb('#09295B'));
  expect(background).toContain(rgb('#0D3A7A'));
  expect(background).toContain(rgb('#1A4A8A'));
  expect(await cssOf(page, 'demo-hero-title', 'color')).toBe(rgb('#FFFFFF'));
  expect(await cssOf(page, 'demo-hero-title', 'font-family')).toMatch(/Bricolage/i);
});

test('hero: spans the whole width of the screen [NFR-UX-02]', async ({ page }) => {
  const box = (await page.getByTestId('demo-hero').boundingBox())!;
  const width = await page.evaluate(() => document.documentElement.clientWidth);

  expect(box.x).toBeLessThanOrEqual(1);
  expect(box.width).toBeGreaterThanOrEqual(width - 2);
});

test('glass tile: translucent, with a thin light border [NFR-UX-02]', async ({ page }) => {
  const tile = page.getByTestId('demo-glass').first();
  const background = await tile.evaluate((element) => getComputedStyle(element).backgroundColor);
  const alpha = parseFloat(background.match(/rgba?\(([^)]+)\)/)![1].split(/[,/]/).map((part) => part.trim())[3] ?? '1');

  expect(alpha).toBeGreaterThan(0);
  expect(alpha).toBeLessThan(0.3);
  expect(await tile.evaluate((element) => getComputedStyle(element).borderTopWidth)).toBe('1px');
  await expect(tile).not.toBeEmpty();
});

test('band: the gradient, and one figure in the light accent [NFR-UX-02]', async ({ page }) => {
  expect(await cssOf(page, 'demo-band', 'background-image')).toContain(rgb('#09295B'));
  expect(await cssOf(page, 'demo-band-figure', 'color')).toBe(rgb('#FFB45A'));
});

test('showcase card: side by side on a desktop, stacked on a phone [NFR-UX-02]', async ({ page }, testInfo) => {
  const card = page.getByTestId('demo-showcase-card');
  const parts = card.locator(':scope > *');
  const first = (await parts.nth(0).boundingBox())!;
  const second = (await parts.nth(1).boundingBox())!;

  if (testInfo.project.name === 'phone') {
    expect(second.y).toBeGreaterThanOrEqual(first.y + first.height - 1);
  } else {
    expect(second.x).toBeGreaterThanOrEqual(first.x + first.width - 1);
    expect(Math.abs(second.y - first.y)).toBeLessThan(2);
  }
});

test('closing band: the gradient again, with the accent button inside [NFR-UX-02]', async ({ page }) => {
  expect(await cssOf(page, 'demo-closing-band', 'background-image')).toContain(rgb('#1A4A8A'));
  await expect(page.getByTestId('demo-closing-band').getByRole('button')).toBeVisible();
});

test('accent button: accent background, deep navy text, never white [NFR-UX-03]', async ({ page }) => {
  const button = page.getByTestId('demo-button-accent');
  const style = await button.evaluate((element) => {
    const computed = getComputedStyle(element);
    return { image: computed.backgroundImage, colour: computed.backgroundColor, text: computed.color };
  });

  expect(`${style.image} ${style.colour}`).toContain(rgb('#FF8603'));
  expect(style.text).toBe(rgb('#061A3D'));
  expect(await button.evaluate((element) => element.tagName)).toBe('BUTTON');
  expect((await button.boundingBox())!.height).toBeGreaterThanOrEqual(44);
});

test('action bar: stays at the bottom of the screen while the page scrolls [NFR-UX-02]', async ({ page }) => {
  const bar = page.getByTestId('demo-action-bar');
  const height = page.viewportSize()!.height;
  const bottom = async () => {
    const box = (await bar.boundingBox())!;
    return box.y + box.height;
  };

  expect(Math.abs((await bottom()) - height)).toBeLessThanOrEqual(1);
  await page.mouse.wheel(0, 600);
  await page.evaluate(() => window.scrollBy(0, 600));
  expect(Math.abs((await bottom()) - height)).toBeLessThanOrEqual(1);
});

test('action bar: its main action fills the width on a phone [NFR-UX-02]', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'phone', 'phone width only');

  const bar = (await page.getByTestId('demo-action-bar').boundingBox())!;
  const action = (await page.getByTestId('demo-action-bar').getByRole('button').last().boundingBox())!;

  expect(action.width).toBeGreaterThanOrEqual(bar.width * 0.6);
  expect(action.height).toBeGreaterThanOrEqual(48);
});

test('page: nothing from another origin, no sideways scroll, accessible [NFR-UX-03]', async ({ page, baseURL }) => {
  const foreign = recordForeignRequests(page, baseURL!);

  await page.goto('/dev/motion');
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(1200);

  expect(foreign).toEqual([]);
  await expectNoSidewaysScroll(page);
  await expectAccessible(page);
});
