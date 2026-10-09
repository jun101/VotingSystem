import { expect, test } from '@playwright/test';
import { isPhone, registerAndEnter } from '../support/admin';
import { cssOf, expectAccessible, expectNoSidewaysScroll, recordForeignRequests, rgb } from '../support/checks';
import { createElection } from '../support/elections';

/*
 * Material periwinkle theme (2026-10-09) on the pages already built: sign-in card, register
 * steps, floating labels, side menu panel, election cards. docs/design/frontend.md section 4.
 * Density and layout of each list stay measured by the slice's own quality spec.
 */

const px = (value: string) => parseFloat(value);

test.describe('sign-in card', () => {
  for (const path of ['/login', '/register']) {
    test(`${path}: one white card, 28 px corners, panel beside the form on a desktop, stacked on a phone [NFR-UX-02]`, async ({ page }) => {
      await page.goto(path);

      await expect(page.getByTestId('auth-card')).toBeVisible();
      expect(px(await cssOf(page, 'auth-card', 'border-radius'))).toBe(28);
      expect(await cssOf(page, 'auth-card', 'background-color')).toBe(rgb('#FFFFFF'));
      expect(await cssOf(page, 'auth-card', 'box-shadow')).not.toBe('none');

      const panel = (await page.getByTestId('auth-panel').boundingBox())!;
      const form = (await page.getByTestId('auth-form-side').boundingBox())!;
      if (isPhone(page)) {
        expect(form.y).toBeGreaterThanOrEqual(panel.y + panel.height - 2);
      } else {
        expect(form.x).toBeGreaterThanOrEqual(panel.x + panel.width * 0.6);
        expect(Math.abs(form.y - panel.y)).toBeLessThanOrEqual(4);
        // No free space: the card is as tall as its tallest side and both fill it.
        expect(Math.abs(form.height - panel.height)).toBeLessThanOrEqual(4);
      }
      await expectNoSidewaysScroll(page);
    });

    test(`${path}: the panel is the periwinkle gradient with white text, the page the bright backdrop [NFR-UX-02]`, async ({ page }) => {
      await page.goto(path);

      const panel = await cssOf(page, 'auth-panel', 'background-image');
      expect(panel).toContain(rgb('#5468D4'));
      expect(panel).toContain(rgb('#7A4FC4'));
      expect(await cssOf(page, 'auth-promise', 'color')).toBe(rgb('#FFFFFF'));
      expect(await cssOf(page, 'auth-promise', 'font-family')).toMatch(/Archivo/i);

      const backdrop = await cssOf(page, 'auth-backdrop', 'background-image');
      expect(backdrop).toContain('linear-gradient(135deg');
      expect(backdrop).toContain(rgb('#C944B6'));
      expect(backdrop).toContain(rgb('#7A92EE'));
    });
  }

  test('/login: the illustration is drawn in the panel and is decorative [NFR-UX-03]', async ({ page }) => {
    await page.goto('/login');

    const art = page.getByTestId('auth-illustration');
    await expect(art).toBeVisible();
    await expect(art).toHaveAttribute('aria-hidden', 'true');
  });

  test('/register: the panel lists the four steps, the first one current [FR-INST-01]', async ({ page }) => {
    await page.goto('/register');

    await expect(page.locator('[data-testid^="register-step-"]')).toHaveCount(4);
    await expect(page.getByTestId('register-step-1')).toHaveAttribute('aria-current', 'step');
    await expect(page.getByTestId('register-step-2')).not.toHaveAttribute('aria-current', 'step');
  });

  test('a field is underlined and its label floats up when it has focus or text [NFR-UX-02]', async ({ page }) => {
    await page.goto('/login');
    const input = page.getByTestId('login-email');
    const label = page.getByTestId('login-email-label');

    const before = px(await label.evaluate((e) => getComputedStyle(e).fontSize));
    await input.focus();
    await expect.poll(async () => px(await label.evaluate((e) => getComputedStyle(e).fontSize))).toBeLessThan(before);
    expect(px(await label.evaluate((e) => getComputedStyle(e).fontSize))).toBe(12);
    expect(await input.evaluate((e) => getComputedStyle(e).borderTopWidth)).toBe('0px');

    await page.getByTestId('login-password').focus();
    await input.fill('a@b.co');
    await expect.poll(async () => px(await label.evaluate((e) => getComputedStyle(e).fontSize))).toBe(12);
  });

  test('the main button is a pill in the primary colour and the page stays accessible [NFR-UX-03]', async ({ page, baseURL }) => {
    const foreign = recordForeignRequests(page, baseURL!);
    await page.goto('/login');

    const button = page.getByTestId('login-submit');
    expect(await button.evaluate((e) => getComputedStyle(e).backgroundColor)).toBe(rgb('#5468D4'));
    expect(await button.evaluate((e) => getComputedStyle(e).color)).toBe(rgb('#FFFFFF'));
    const height = (await button.boundingBox())!.height;
    expect(px(await button.evaluate((e) => getComputedStyle(e).borderTopLeftRadius))).toBeGreaterThanOrEqual(height / 2 - 1);
    await expectAccessible(page);
    expect(foreign).toEqual([]);
  });

  test('with "reduce motion" the backdrop and the illustration stand still [NFR-UX-03]', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce', baseURL });
    const page = await context.newPage();
    await page.goto('/login');

    expect(await page.getByTestId('auth-backdrop').evaluate((e) => getComputedStyle(e).animationName)).toBe('none');
    expect(await page.getByTestId('auth-illustration').evaluate((e) => getComputedStyle(e.querySelector('*') as Element).animationName)).toBe('none');
    await expect(page.getByTestId('login-form')).toBeVisible();
    await context.close();
  });
});

test.describe('admin', () => {
  test('the side menu is the periwinkle panel, the current page a white pill [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections');
    if (isPhone(page)) await page.getByTestId('menu-button').click();

    const menu = await cssOf(page, 'side-menu', 'background-image');
    expect(menu).toContain(rgb('#5468D4'));
    expect(menu).toContain(rgb('#7A4FC4'));
    const current = page.getByTestId('menu-link-elections');
    expect(await current.evaluate((e) => getComputedStyle(e).backgroundColor)).toBe(rgb('#FFFFFF'));
    expect(await current.evaluate((e) => getComputedStyle(e).color)).not.toBe(rgb('#FFFFFF'));
  });

  test('the top bar carries the title and the avatar, and no band is left between it and the first row [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections');

    const bar = (await page.getByTestId('top-bar').boundingBox())!;
    const row = (await page.getByTestId('tile-all').boundingBox())!;
    expect(row.y - (bar.y + bar.height)).toBeLessThanOrEqual(24);
    await expect(page.getByTestId('top-bar-title')).toBeVisible();
  });

  test('an election card: 20 px corners, resting shadow, a 76 px gradient cover, lifts on hover [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await createElection(page, 'Conseil des élèves', '2026-11-02T12:00:00Z', '2026-11-03T12:00:00Z');
    await page.goto('/admin/elections');

    const card = page.getByTestId('election-card-1');
    expect(px(await card.evaluate((e) => getComputedStyle(e).borderTopLeftRadius))).toBe(20);
    expect(await card.evaluate((e) => getComputedStyle(e).boxShadow)).not.toBe('none');

    const band = page.getByTestId('election-band-1');
    expect((await band.boundingBox())!.height).toBe(76);
    expect(await band.evaluate((e) => getComputedStyle(e).backgroundImage)).toContain('linear-gradient');

    if (!isPhone(page)) {
      const before = (await card.boundingBox())!.y;
      await card.hover();
      await expect.poll(async () => before - (await card.boundingBox())!.y).toBeGreaterThanOrEqual(4);
    }
  });

  test('the status tiles are white with a coloured line, the selected one primary [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await createElection(page, 'Une', '2026-11-02T12:00:00Z', '2026-11-03T12:00:00Z');
    await page.goto('/admin/elections');

    expect(await cssOf(page, 'tile-all', 'background-color')).toBe(rgb('#5468D4'));
    expect(await cssOf(page, 'tile-all', 'color')).toBe(rgb('#FFFFFF'));
    expect(await cssOf(page, 'tile-draft', 'background-color')).toBe(rgb('#FFFFFF'));
  });

  test('the creation tile is dashed, with a round plus button, and is the first cell [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await createElection(page, 'Une', '2026-11-02T12:00:00Z', '2026-11-03T12:00:00Z');
    await page.goto('/admin/elections');

    expect(await cssOf(page, 'election-new-tile', 'border-top-style')).toBe('dashed');
    const tile = (await page.getByTestId('election-new-tile').boundingBox())!;
    const card = (await page.getByTestId('election-card-1').boundingBox())!;
    expect(tile.x).toBeLessThanOrEqual(card.x);
    expect(tile.y).toBeLessThanOrEqual(card.y);
  });

  test('the admin pages are accessible with the new colours [NFR-UX-03]', async ({ page }) => {
    await registerAndEnter(page);
    for (const path of ['/admin', '/admin/elections', '/admin/elections/new', '/admin/institution', '/admin/account']) {
      await page.goto(path);
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);
    }
  });
});
