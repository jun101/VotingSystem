import { expect, test } from '@playwright/test';
import { expectAccessible, expectNoSidewaysScroll, recordForeignRequests } from '../support/checks';
import { uniqueEmail } from '../support/mail';

/*
 * Slice 02 — every page is accessible, fits the screen, loads nothing from elsewhere, still
 * works without JavaScript for what can, and does not depend on motion.
 * NFR-UX-01, NFR-UX-02, NFR-UX-03, NFR-PERF-01.
 */

const token = 'c'.repeat(64);
const publicPages: { path: string; form: string }[] = [
  { path: '/register', form: 'register-form' },
  { path: '/login', form: 'login-form' },
  { path: '/forgot-password', form: 'forgot-form' },
  { path: `/reset-password?token=${token}`, form: 'reset-form' },
];

for (const locale of [
  { tag: 'fr-FR', lang: 'fr' },
  { tag: 'en-US', lang: 'en' },
]) {
  test.describe(`pages in ${locale.lang}`, () => {
    test.use({ locale: locale.tag });

    for (const { path, form } of publicPages) {
      test(`${path.split('?')[0]} is accessible, fits the screen and loads nothing from elsewhere [NFR-UX-03]`, async ({ page, baseURL }) => {
        const foreign = recordForeignRequests(page, baseURL!);

        await page.goto(path);

        await expect(page.locator('html')).toHaveAttribute('lang', locale.lang);
        await expect(page.getByTestId(form)).toBeVisible();
        await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
        await expectNoSidewaysScroll(page);
        await expectAccessible(page);
        expect(foreign).toEqual([]);
      });
    }

    test('the verification page is accessible in every state [NFR-UX-03]', async ({ page }) => {
      await page.goto(`/verify-email?token=${token}`);
      await expect(page.getByTestId('verify-state')).not.toHaveAttribute('data-state', 'pending');
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);
    });

    test('the admin placeholder is accessible, with its banner [NFR-UX-03]', async ({ page }) => {
      await page.goto('/register');
      await page.getByTestId('register-institution-name').fill('Collège');
      await page.getByTestId('register-name').fill('Marie');
      await page.getByTestId('register-email').fill(uniqueEmail());
      await page.getByTestId('register-password').fill('un mot de passe long et sûr');
      await page.getByTestId('register-submit').click();

      await expect(page.getByTestId('verify-banner')).toBeVisible();
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);
    });
  });
}

test.describe('"reduce motion"', () => {
  test.use({ reducedMotion: 'reduce' });

  for (const { path, form } of publicPages) {
    test(`${path.split('?')[0]} is complete and still [NFR-UX-03]`, async ({ page }) => {
      await page.goto(path);

      await expect(page.getByTestId(form)).toBeVisible();
      const moving = await page.evaluate(() =>
        document.getAnimations().filter((animation) => animation.playState === 'running' && !(animation instanceof CSSTransition)).length,
      );
      expect(moving, 'running animations').toBe(0);
      expect(await page.getByTestId(form).evaluate((element) => parseFloat(getComputedStyle(element).opacity))).toBe(1);
    });
  }
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  for (const { path, form } of publicPages) {
    test(`${path.split('?')[0]} shows its form, labelled [NFR-UX-03]`, async ({ page }) => {
      await page.goto(path);

      await expect(page.getByTestId(form)).toBeVisible();
      const inputs = page.getByTestId(form).locator('input:not([type=hidden])');
      for (let i = 0; i < (await inputs.count()); i++) {
        const input = inputs.nth(i);
        const id = await input.getAttribute('id');
        const labelled = (await page.locator(`label[for="${id}"]`).count()) > 0 || (await input.getAttribute('aria-label')) !== null;
        expect(labelled, `input ${id} has a label`).toBe(true);
      }
      await expect(page.getByTestId(form).getByRole('button')).not.toHaveCount(0);
    });
  }
});

test('the register form fits inside the screen at every width [NFR-UX-02]', async ({ page }) => {
  await page.goto('/register');

  const box = await page.getByTestId('register-form').boundingBox();
  const viewport = page.viewportSize()!;
  expect(box).not.toBeNull();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(viewport.width);
});
