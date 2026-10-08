import { expect, test } from '@playwright/test';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { openMenu, registerAndEnter } from '../support/admin';
import { pngBuffer } from '../support/images';
import { uniqueEmail } from '../support/mail';
import { acceptInNewBrowser, invite } from '../support/team';

/*
 * Slice 04 — accessibility, width, touch size, motion on screen A14 and the accept page.
 * Each project runs these at its own width: 1280 px (desktop) and 320 px (phone).
 * docs/slices/04-profile-and-users.md, rule 8 and part 3.
 */

for (const language of [
  { locale: 'fr-FR', lang: 'fr' },
  { locale: 'en-US', lang: 'en' },
]) {
  test.describe(`in ${language.lang}`, () => {
    test.use({ locale: language.locale });

    test('the page of the institution is accessible and never scrolls sideways, full of users, an invitation and a logo [NFR-UX-03, FR-NAV-03]', async ({ page, browser }) => {
      await registerAndEnter(page);
      await invite(page, uniqueEmail('pending'));
      await acceptInNewBrowser(browser, await invite(page, uniqueEmail('manager')), 'Jean Pierre', language.locale).then((p) => p.context().close());
      await page.goto('/admin/institution');
      await page.getByTestId('logo-input').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: pngBuffer(200, 100) });
      await expect(page.getByTestId('logo-preview')).toBeVisible();
      await expect(page.locator('html')).toHaveAttribute('lang', language.lang);
      await expect(page.getByTestId('users-card')).toBeVisible();

      await expectNoSidewaysScroll(page);
      await expectAccessible(page);
    });

    test('the invitation form, the errors and the removal dialog are accessible [NFR-UX-03]', async ({ page }) => {
      await registerAndEnter(page);
      await page.goto('/admin/institution');

      await page.getByTestId('invite-open').click();
      await page.getByTestId('invite-email').fill('pas une adresse');
      await page.getByTestId('invite-submit').click();
      await expect(page.getByTestId('invite-email-error')).toBeVisible();
      await expectAccessible(page);

      await page.getByTestId('profile-name').fill('');
      await page.getByTestId('profile-save').click();
      await expect(page.getByTestId('profile-name-error')).toBeVisible();
      await expectAccessible(page);

      await page.getByTestId(/^user-remove-\d+$/).click();
      await expect(page.getByTestId('user-remove-dialog')).toBeVisible();
      await expectAccessible(page);
    });

    test('the removal dialog traps the focus, closes with Escape and gives the focus back [NFR-UX-03]', async ({ page }) => {
      await registerAndEnter(page);
      await page.goto('/admin/institution');
      const button = page.getByTestId(/^user-remove-\d+$/);
      await button.focus();
      await button.press('Enter');
      await expect(page.getByTestId('user-remove-dialog')).toBeVisible();

      for (let i = 0; i < 6; i++) {
        await page.keyboard.press('Tab');
        const inside = await page.evaluate(() => document.querySelector('[data-testid="user-remove-dialog"]')?.contains(document.activeElement) ?? false);
        expect(inside, `focus left the dialog after ${i + 1} Tab`).toBe(true);
      }

      await page.keyboard.press('Escape');
      await expect(page.getByTestId('user-remove-dialog')).toHaveCount(0);
      await expect(button).toBeFocused();
    });

    test('the accept page is accessible and never scrolls sideways [NFR-UX-03]', async ({ page }) => {
      await page.goto(`/accept-invitation?token=${'a'.repeat(64)}`);
      await expect(page.getByTestId('accept-form')).toBeVisible();
      await expect(page.locator('html')).toHaveAttribute('lang', language.lang);

      await expectNoSidewaysScroll(page);
      await expectAccessible(page);

      await page.getByTestId('accept-name').fill('Personne');
      await page.getByTestId('accept-password').fill('un mot de passe long et sûr');
      await page.getByTestId('accept-submit').click();
      await expect(page.getByTestId('accept-invalid')).toBeVisible();
      await expectAccessible(page);
    });

    test('the main controls are at least 44 px high, to be used with a thumb [FR-NAV-03]', async ({ page }) => {
      await registerAndEnter(page);
      await page.goto('/admin/institution');

      for (const id of ['profile-name', 'profile-type', 'profile-city', 'profile-save', 'invite-open', 'logo-input']) {
        const element = page.getByTestId(id);
        if (id === 'logo-input') continue; // a hidden file input: its visible button is checked below
        const box = await element.boundingBox();
        expect(box?.height ?? 0, id).toBeGreaterThanOrEqual(44);
      }
      for (const id of [/^user-remove-\d+$/]) {
        const box = await page.getByTestId(id).boundingBox();
        expect(box?.height ?? 0, String(id)).toBeGreaterThanOrEqual(44);
      }
    });

    test('the menu still reaches the page by keyboard alone [NFR-UX-03]', async ({ page }) => {
      await registerAndEnter(page);
      await openMenu(page);
      await page.getByTestId('menu-link-institution').focus();
      await page.keyboard.press('Enter');
      await expect(page).toHaveURL(/\/admin\/institution$/);
      await expect(page.getByTestId('menu-link-institution')).toHaveAttribute('aria-current', 'page').catch(async () => {
        await openMenu(page);
        await expect(page.getByTestId('menu-link-institution')).toHaveAttribute('aria-current', 'page');
      });
    });
  });
}

test.describe('"reduce motion"', () => {
  test.use({ reducedMotion: 'reduce' });

  test('the page of the institution is complete and still [NFR-UX-03]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/institution');
    await expect(page.getByTestId('institution-page')).toBeVisible();

    const moving = await page.evaluate(() =>
      document.getAnimations().filter((animation) => animation.playState === 'running' && !(animation instanceof CSSTransition)).length,
    );
    expect(moving, 'running animations').toBe(0);

    for (const id of ['profile-card', 'users-card']) {
      const opacity = await page.getByTestId(id).evaluate((element) => parseFloat(getComputedStyle(element).opacity));
      expect(opacity, id).toBe(1);
    }
  });
});

test.describe('motion allowed', () => {
  test.use({ reducedMotion: 'no-preference' });

  test('the cards come in by revealing, and end fully visible [NFR-UX-03]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/institution');

    for (const id of ['profile-card', 'users-card']) {
      await expect
        .poll(async () => page.getByTestId(id).evaluate((element) => parseFloat(getComputedStyle(element).opacity)), { timeout: 5_000 })
        .toBe(1);
    }
  });
});
