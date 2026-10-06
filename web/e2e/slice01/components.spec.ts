import { expect, test } from '@playwright/test';
import { cssOf, expectAccessible, expectNoSidewaysScroll, rgb } from '../support/checks';

/*
 * Slice 01 — the base components, shown on /dev/components.
 * Values come from docs/design/frontend.md section 4.
 */

test.beforeEach(async ({ page }) => {
  await page.goto('/dev/components');
});

test.describe('button', () => {
  test('primary: colour, shape and touch size [NFR-UX-02]', async ({ page }) => {
    const button = page.getByTestId('demo-button-primary');

    await expect(button).toBeVisible();
    expect(await button.evaluate((element) => element.tagName)).toBe('BUTTON');
    expect(await cssOf(page, 'demo-button-primary', 'background-color')).toBe(rgb('#1E3A8A'));
    expect(await cssOf(page, 'demo-button-primary', 'color')).toBe(rgb('#FFFFFF'));
    expect(await cssOf(page, 'demo-button-primary', 'border-top-left-radius')).toBe('10px');
    expect((await button.boundingBox())!.height).toBeGreaterThanOrEqual(44);
  });

  test('secondary, danger and quiet variants exist and differ [NFR-UX-02]', async ({ page }) => {
    for (const variant of ['secondary', 'danger', 'quiet']) {
      await expect(page.getByTestId(`demo-button-${variant}`)).toBeVisible();
      expect((await page.getByTestId(`demo-button-${variant}`).boundingBox())!.height).toBeGreaterThanOrEqual(44);
    }

    expect(await cssOf(page, 'demo-button-secondary', 'background-color')).toBe(rgb('#FFFFFF'));
    expect(await cssOf(page, 'demo-button-danger', 'color')).toBe(rgb('#9A2A0A'));
  });

  test('voter size is taller [NFR-UX-02]', async ({ page }) => {
    expect((await page.getByTestId('demo-button-voter').boundingBox())!.height).toBeGreaterThanOrEqual(48);
  });

  test('disabled cannot be used [NFR-UX-03]', async ({ page }) => {
    await expect(page.getByTestId('demo-button-disabled')).toBeDisabled();
  });

  test('loading says so to assistive technology and cannot be pressed twice [NFR-UX-03]', async ({ page }) => {
    const button = page.getByTestId('demo-button-loading');

    await expect(button).toHaveAttribute('aria-busy', 'true');
    await expect(button).toBeDisabled();
  });

  test('shows a visible focus ring with the keyboard [NFR-UX-03]', async ({ page }) => {
    const button = page.getByTestId('demo-button-primary');
    const ring = () =>
      button.evaluate((element) => {
        const style = getComputedStyle(element);
        return `${style.outlineStyle} ${style.outlineWidth} | ${style.boxShadow}`;
      });

    const before = await ring();
    for (let i = 0; i < 40; i++) {
      await page.keyboard.press('Tab');
      if (await button.evaluate((element) => element === document.activeElement)) break;
    }
    await expect(button).toBeFocused();

    expect(await ring()).not.toBe(before);
  });
});

test.describe('input', () => {
  test('has a label tied to the field [NFR-UX-03]', async ({ page }) => {
    const input = page.getByTestId('demo-input');

    expect(await input.evaluate((element) => (element as HTMLInputElement).labels?.length ?? 0)).toBeGreaterThan(0);
    expect((await input.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    expect(await cssOf(page, 'demo-input', 'border-top-left-radius')).toBe('10px');
  });

  test('text is at least 16 px so a phone does not zoom [NFR-UX-02]', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'phone', 'phone width only');

    expect(parseFloat(await cssOf(page, 'demo-input', 'font-size'))).toBeGreaterThanOrEqual(16);
  });

  test('an error is announced and tied to the field [NFR-UX-03]', async ({ page }) => {
    const input = page.getByTestId('demo-input-error');

    await expect(input).toHaveAttribute('aria-invalid', 'true');

    const describedBy = await input.getAttribute('aria-describedby');
    expect(describedBy).toBeTruthy();

    const message = page.locator(`[id="${describedBy!.split(' ')[0]}"]`);
    await expect(message).toBeVisible();
    await expect(message).not.toBeEmpty();
  });
});

test.describe('card and pill', () => {
  test('card: white surface, border, large radius, no shadow [NFR-UX-02]', async ({ page }) => {
    await expect(page.getByTestId('demo-card')).toBeVisible();
    expect(await cssOf(page, 'demo-card', 'background-color')).toBe(rgb('#FFFFFF'));
    expect(await cssOf(page, 'demo-card', 'border-top-left-radius')).toBe('16px');
    expect(await cssOf(page, 'demo-card', 'border-top-width')).toBe('1px');
    expect(await cssOf(page, 'demo-card', 'border-top-color')).toBe(rgb('#D8DEE9'));
    expect(await cssOf(page, 'demo-card', 'box-shadow')).toBe('none');
  });

  test('pill: fully rounded, always with text, one per tone [NFR-UX-03]', async ({ page }) => {
    for (const tone of ['neutral', 'primary', 'teal', 'warm', 'danger']) {
      const pill = page.getByTestId(`demo-pill-${tone}`);

      await expect(pill).toBeVisible();
      await expect(pill).not.toBeEmpty();
      expect(parseFloat(await cssOf(page, `demo-pill-${tone}`, 'border-top-left-radius'))).toBeGreaterThanOrEqual(999);
    }

    expect(await cssOf(page, 'demo-pill-teal', 'background-color')).toBe(rgb('#D9F2EE'));
    expect(await cssOf(page, 'demo-pill-teal', 'color')).toBe(rgb('#0B5750'));
  });
});

test.describe('page', () => {
  test('uses the canvas colour behind the cards [NFR-UX-02]', async ({ page }) => {
    const background = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);

    expect(background).toBe(rgb('#F3F5F9'));
  });

  test('fits the screen without scrolling sideways [NFR-UX-02]', async ({ page }) => {
    await expectNoSidewaysScroll(page);
  });

  test('passes the automated accessibility check [NFR-UX-03]', async ({ page }) => {
    await expectAccessible(page);
  });
});
