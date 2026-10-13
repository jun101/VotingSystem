import { expect, test } from '@playwright/test';
import { expectNoSidewaysScroll } from '../support/checks';
import { registerAndEnter } from '../support/admin';
import { ADMIN_EMAIL, ADMIN_PASSWORD, signInAs } from '../support/platform';

/*
 * The user manuals, `/guide/[role]`: public for the voter's, gated by role for the others, reached from the user menu.
 * The pictures are taken by `capture.spec.ts` (on demand, not here).
 */

test('a visitor reads the voter manual with numbered steps and no other manual', async ({ page }) => {
  await page.goto('/guide/voter');

  await expect(page.getByTestId('guide-title')).toHaveText('Électeur');
  await expect(page.getByTestId('guide-section-code')).toBeVisible();
  await expect(page.locator('[data-testid="guide-section-code"] ol li').first()).toContainText('Ouvrez la page de vote');
  await expect(page.getByTestId('guide-tab-voter')).toHaveAttribute('aria-current', 'page');
  await expect(page.getByTestId('guide-tab-owner')).toHaveCount(0);
  await expectNoSidewaysScroll(page);
});

test('a visitor who opens another manual is sent to their own', async ({ page }) => {
  await page.goto('/guide/owner');

  await expect(page.getByTestId('guide-denied')).toBeVisible();
  await page.getByTestId('guide-open-mine').click();
  await expect(page).toHaveURL(/\/guide\/voter$/);
});

test('an unknown manual is a 404', async ({ page }) => {
  const response = await page.goto('/guide/nothing');

  expect(response?.status()).toBe(404);
});

test('an owner opens their manual from the user menu and may read the manager and installation ones', async ({ page }) => {
  await registerAndEnter(page);
  await page.getByTestId('user-menu').click();
  await page.getByTestId('user-menu-guide').click();

  await expect(page).toHaveURL(/\/guide\/owner$/);
  await expect(page.getByTestId('guide-title')).toHaveText('Propriétaire');
  for (const tab of ['voter', 'manager', 'owner', 'install']) await expect(page.getByTestId(`guide-tab-${tab}`)).toBeVisible();
  await expect(page.getByTestId('guide-tab-platform')).toHaveCount(0);

  await page.getByTestId('guide-tab-install').click();
  await expect(page.getByTestId('guide-section-linux')).toBeVisible();

  await page.goto('/guide/platform');
  await expect(page.getByTestId('guide-denied')).toBeVisible();
});

test('the platform administrator reads the platform manual', async ({ page }) => {
  await signInAs(page, ADMIN_EMAIL, ADMIN_PASSWORD);
  await expect(page).toHaveURL(/\/platform$/);
  await page.getByTestId('platform-guide').click();

  await expect(page).toHaveURL(/\/guide\/platform$/);
  await expect(page.getByTestId('guide-section-suspend')).toBeVisible();
});
