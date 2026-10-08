import { expect, test } from '@playwright/test';
import { PASSWORD, registerAndEnter } from '../support/admin';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { totp } from '../support/totp';
import { enableTwoFactor, openAccount, signOutAndSignIn } from '../support/twofactor';

/*
 * Slice 04b — accessibility, width, touch size, focus and motion of the account page and the code step.
 * Each project runs these at its own width: 1280 px (desktop) and 320 px (phone).
 * docs/slices/04b-two-factor.md, rule 9 and part 2.
 */

const current = (secret: string) => totp(secret);

for (const language of [
  { locale: 'fr-FR', lang: 'fr' },
  { locale: 'en-US', lang: 'en' },
]) {
  test.describe(`in ${language.lang}`, () => {
    test.use({ locale: language.locale });

    test('the account page is accessible, off then on, and never scrolls sideways [NFR-UX-03, FR-NAV-03]', async ({ page }) => {
      await registerAndEnter(page);
      await openAccount(page);
      await expect(page.locator('html')).toHaveAttribute('lang', language.lang);
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);

      await enableTwoFactor(page, PASSWORD, current);
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);
    });

    test('the set-up steps are accessible: password, QR code, code, recovery codes [NFR-UX-03]', async ({ page }) => {
      await registerAndEnter(page);
      await openAccount(page);
      await page.getByTestId('two-factor-setup-open').click();
      await expectAccessible(page);

      await page.getByTestId('two-factor-password').fill(PASSWORD);
      await page.getByTestId('two-factor-setup-submit').click();
      await expect(page.getByTestId('two-factor-qr')).toBeVisible();
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);

      await page.getByTestId('two-factor-code').fill('000000');
      await page.getByTestId('two-factor-confirm').click();
      await expect(page.getByTestId('two-factor-code-error')).toBeVisible();
      await expectAccessible(page);

      const secret = (await page.getByTestId('two-factor-secret').innerText()).replace(/\s/g, '');
      await page.getByTestId('two-factor-code').fill(totp(secret));
      await page.getByTestId('two-factor-confirm').click();
      await expect(page.getByTestId('recovery-codes')).toBeVisible();
      // Focus moves to the list so a screen reader starts at the codes.
      await expect
        .poll(() => page.evaluate(() => document.querySelector('[data-testid="recovery-codes"]')?.contains(document.activeElement) ?? false))
        .toBe(true);
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);
    });

    test('the code step of the sign-in is accessible, with an error, and in recovery mode [NFR-UX-03]', async ({ page }) => {
      const { email } = await registerAndEnter(page);
      await enableTwoFactor(page, PASSWORD, current);
      await signOutAndSignIn(page, email);
      await expect(page.locator('html')).toHaveAttribute('lang', language.lang);
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);

      await page.getByTestId('challenge-code').fill('000000');
      await page.getByTestId('challenge-submit').click();
      await expect(page.getByTestId('challenge-code-error')).toBeVisible();
      await expectAccessible(page);

      await page.getByTestId('challenge-recovery-toggle').click();
      await expect(page.getByTestId('challenge-recovery-code')).toBeVisible();
      await expectAccessible(page);
    });

    test('the code field asks the phone for a numeric keyboard and a one-time code [NFR-UX-02]', async ({ page }) => {
      const { email } = await registerAndEnter(page);
      await enableTwoFactor(page, PASSWORD, current);
      await signOutAndSignIn(page, email);

      const field = page.getByTestId('challenge-code');
      await expect(field).toHaveAttribute('inputmode', 'numeric');
      await expect(field).toHaveAttribute('autocomplete', 'one-time-code');
    });

    test('the dialogs are accessible, trap the focus, close with Escape and give the focus back [NFR-UX-03]', async ({ page }) => {
      await registerAndEnter(page);
      await enableTwoFactor(page, PASSWORD, current);
      await openAccount(page);

      const open = page.getByTestId('two-factor-disable-open');
      await open.focus();
      await open.press('Enter');
      await expect(page.getByTestId('two-factor-disable-dialog')).toBeVisible();
      await expectAccessible(page);

      for (let i = 0; i < 6; i++) {
        await page.keyboard.press('Tab');
        const inside = await page.evaluate(
          () => document.querySelector('[data-testid="two-factor-disable-dialog"]')?.contains(document.activeElement) ?? false,
        );
        expect(inside, `focus left the dialog after ${i + 1} Tab`).toBe(true);
      }

      await page.keyboard.press('Escape');
      await expect(page.getByTestId('two-factor-disable-dialog')).toHaveCount(0);
      await expect(open).toBeFocused();
    });

    test('the main controls are at least 44 px high, to be used with a thumb [FR-NAV-03]', async ({ page }) => {
      await registerAndEnter(page);
      await openAccount(page);
      for (const id of ['two-factor-setup-open']) {
        const box = await page.getByTestId(id).boundingBox();
        expect(box?.height ?? 0, id).toBeGreaterThanOrEqual(44);
      }
    });
  });
}

test.describe('"reduce motion"', () => {
  test.use({ reducedMotion: 'reduce' });

  test('the account page is complete and still [NFR-UX-03]', async ({ page }) => {
    await registerAndEnter(page);
    await openAccount(page);

    const moving = await page.evaluate(() =>
      document.getAnimations().filter((animation) => animation.playState === 'running' && !(animation instanceof CSSTransition)).length,
    );
    expect(moving, 'running animations').toBe(0);
    const opacity = await page.getByTestId('security-card').evaluate((element) => parseFloat(getComputedStyle(element).opacity));
    expect(opacity).toBe(1);
  });
});
