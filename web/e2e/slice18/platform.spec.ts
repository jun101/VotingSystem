import { expect, test } from '@playwright/test';
import { PASSWORD, registerAndEnter, signOut } from '../support/admin';
import { enterPlatform, findInstitution, signInAs } from '../support/platform';

/*
 * Slice 18, screen A16 (docs/slices/18-platform-administration.md "Test ids"): the platform admin finds an institution,
 * suspends it with a reason, its people are refused with the reason, then it is reactivated.
 * `make e2e` creates the platform admin first (support/platform.ts).
 */

test.describe('platform administration [FR-ADM-01, FR-INST-06]', () => {
  test('suspends an institution with a reason and reactivates it', async ({ page, browser }) => {
    const name = `Collège Suspendu ${Date.now()}`;
    const owner = await registerAndEnter(page, { institution: name });
    await signOut(page);

    await enterPlatform(page);
    await findInstitution(page, name);
    await expect(page.getByTestId('platform-status-1')).toHaveAttribute('data-status', 'active');

    await page.getByTestId('platform-suspend-1').click();
    await page.getByTestId('suspend-confirm').click();
    // A reason is required: nothing happens without one.
    await expect(page.getByTestId('suspend-reason')).toBeVisible();
    await expect(page.getByTestId('platform-status-1')).toHaveAttribute('data-status', 'active');

    await page.getByTestId('suspend-reason').fill('Paiement non reçu');
    await page.getByTestId('suspend-confirm').click();
    await expect(page.getByTestId('platform-status-1')).toHaveAttribute('data-status', 'suspended');
    await expect(page.getByTestId('platform-reactivate-1')).toBeVisible();

    // The owner is refused, and told why.
    const ownerPage = await (await browser.newContext({ locale: 'fr-FR' })).newPage();
    await signInAs(ownerPage, owner.email, PASSWORD);
    await expect(ownerPage.getByTestId('login-suspended')).toBeVisible();
    await expect(ownerPage.getByTestId('login-suspended-reason')).toHaveText('Paiement non reçu');
    await expect(ownerPage).toHaveURL(/\/login/);

    await page.getByTestId('platform-reactivate-1').click();
    await page.getByTestId('reactivate-confirm').click();
    await expect(page.getByTestId('platform-status-1')).toHaveAttribute('data-status', 'active');

    await signInAs(ownerPage, owner.email, PASSWORD);
    await expect(ownerPage).toHaveURL(/\/admin$/);
  });

  test('filters institutions by status and says when nothing matches', async ({ page }) => {
    await enterPlatform(page);

    await page.getByTestId('platform-search').fill('zzzz-aucun-etablissement');
    await expect(page.getByTestId('platform-empty')).toBeVisible();
    await expect(page.getByTestId('platform-institution-1')).toHaveCount(0);

    await page.getByTestId('platform-search').fill('');
    await page.getByTestId('platform-filter-status').selectOption('suspended');
    await expect(page.getByTestId('platform-page')).toBeVisible();
    for (const card of await page.locator('[data-testid^="platform-status-"]').all()) {
      await expect(card).toHaveAttribute('data-status', 'suspended');
    }
  });

  test('shows counts only, never a name or a code of a school', async ({ page }) => {
    await enterPlatform(page);

    await expect(page.getByTestId('platform-counts-1')).toBeVisible();
    await expect(page.getByTestId('platform-page')).not.toContainText('@');
  });

  test('an owner who opens /platform is sent back to their own area', async ({ page }) => {
    await registerAndEnter(page);

    await page.goto('/platform');

    await expect(page).toHaveURL(/\/admin$/);
  });

  test('a signed-out visitor opening /platform is sent to the sign-in page', async ({ page }) => {
    await page.goto('/platform');

    await expect(page).toHaveURL(/\/login/);
  });
});
