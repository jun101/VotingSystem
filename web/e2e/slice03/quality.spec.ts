import { expect, test } from '@playwright/test';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { isPhone, openMenu, registerAndEnter } from '../support/admin';

/*
 * Slice 03 — accessibility, width, motion. Each project runs these at its own width:
 * 1280 px (desktop) and 320 px (phone). docs/slices/03-admin-shell-and-tenant-isolation.md,
 * rule 8 and "Motion".
 */

const pages = [
  { path: '/admin', name: 'the dashboard' },
  { path: '/admin/elections', name: 'a page not available yet' },
  { path: '/admin/audit', name: 'another page not available yet' },
];

for (const language of [
  { locale: 'fr-FR', lang: 'fr' },
  { locale: 'en-US', lang: 'en' },
]) {
  test.describe(`in ${language.lang}`, () => {
    test.use({ locale: language.locale });

    for (const { path, name } of pages) {
      test(`${name} is accessible and never scrolls sideways [NFR-UX-03, FR-NAV-03]`, async ({ page }) => {
        await registerAndEnter(page);
        await page.goto(path);
        await expect(page.locator('html')).toHaveAttribute('lang', language.lang);
        await expect(page.getByTestId('admin-shell')).toBeVisible();

        await expectNoSidewaysScroll(page);
        await expectAccessible(page);
      });
    }

    test('the open menu is accessible [NFR-UX-03]', async ({ page }) => {
      await registerAndEnter(page);
      await openMenu(page);
      await expect(page.getByTestId('side-menu')).toBeVisible();

      await expectNoSidewaysScroll(page);
      await expectAccessible(page);
    });

    test('the user menu is accessible when open [NFR-UX-03]', async ({ page }) => {
      await registerAndEnter(page);
      await page.getByTestId('user-menu').click();
      await expect(page.getByTestId('signout-button')).toBeVisible();

      await expectAccessible(page);
    });

    test('the controls are at least 44 px high, to be used with a thumb [FR-NAV-03]', async ({ page }) => {
      await registerAndEnter(page);
      await openMenu(page);

      for (const id of ['menu-link-dashboard', 'menu-link-elections', 'menu-link-institution', 'menu-link-audit', 'language-switch', 'menu-new-election']) {
        const box = await page.getByTestId(id).boundingBox();
        expect(box?.height ?? 0, id).toBeGreaterThanOrEqual(44);
      }
      if (isPhone(page)) {
        const button = await page.getByTestId('menu-button').boundingBox();
        expect(button?.width ?? 0).toBeGreaterThanOrEqual(44);
      }
    });
  });
}

test.describe('"reduce motion"', () => {
  test.use({ reducedMotion: 'reduce' });

  test('the dashboard is complete and still, and the drawer does not slide [NFR-UX-03]', async ({ page }) => {
    await registerAndEnter(page);
    await expect(page.getByTestId('dashboard')).toBeVisible();

    const moving = await page.evaluate(() =>
      document.getAnimations().filter((animation) => animation.playState === 'running' && !(animation instanceof CSSTransition)).length,
    );
    expect(moving, 'running animations').toBe(0);

    for (const card of ['open-election', 'todo', 'figures', 'activity', 'latest']) {
      const opacity = await page.getByTestId(`dashboard-card-${card}`).evaluate((element) => parseFloat(getComputedStyle(element).opacity));
      expect(opacity, card).toBe(1);
    }

    if (isPhone(page)) {
      await page.getByTestId('menu-button').click();
      const transition = await page.getByTestId('menu-drawer').evaluate((element) => getComputedStyle(element).transitionDuration);
      expect(transition.split(',').every((value) => parseFloat(value) === 0), `transition ${transition}`).toBe(true);
    }
  });
});

test.describe('motion allowed', () => {
  test.use({ reducedMotion: 'no-preference' });

  test('the dashboard cards come in by revealing, and end fully visible [NFR-UX-03]', async ({ page }) => {
    await registerAndEnter(page);
    await expect(page.getByTestId('dashboard')).toBeVisible();

    await expect
      .poll(async () => page.getByTestId('dashboard-card-latest').evaluate((element) => parseFloat(getComputedStyle(element).opacity)), { timeout: 5_000 })
      .toBe(1);
  });
});
