import { expect, test } from '@playwright/test';
import { registerAndEnter, signOut } from '../support/admin';
import { enterPlatform, findInstitution } from '../support/platform';

/*
 * Slice 18c (docs/slices/18-platform-administration.md "Slice 18c"): the platform admin acts as a school's owner from
 * the platform screen, sees a banner on every admin page while acting, and ends it.
 */

test.describe('platform admin acts as the owner [FR-ADM-01]', () => {
  test('enters an institution, sees the banner, does owner work and ends', async ({ page }) => {
    const name = `Collège Entré ${Date.now()}`;
    await registerAndEnter(page, { institution: name });
    await signOut(page);

    await enterPlatform(page);
    await findInstitution(page, name);
    await page.getByTestId('platform-impersonate-1').click();

    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByTestId('impersonation-banner')).toBeVisible();
    await expect(page.getByTestId('impersonation-banner')).toContainText(name);

    // The banner stays on the other admin pages, and the owner's pages work.
    await page.goto('/admin/elections');
    await expect(page.getByTestId('impersonation-banner')).toBeVisible();
    await page.goto('/admin/audit');
    await expect(page.getByTestId('audit-page')).toBeVisible();
    await expect(page.getByTestId('audit-action-1')).toHaveAttribute('data-action', 'platform.impersonation_started');

    await page.getByTestId('impersonation-end').click();
    await expect(page).toHaveURL(/\/platform$/);
    await expect(page.getByTestId('platform-page')).toBeVisible();
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/platform$/);
  });

  test('a suspended institution has no enter button', async ({ page }) => {
    const name = `Collège Fermé ${Date.now()}`;
    await registerAndEnter(page, { institution: name });
    await signOut(page);

    await enterPlatform(page);
    await findInstitution(page, name);
    await page.getByTestId('platform-suspend-1').click();
    await page.getByTestId('suspend-reason').fill('Motif de test');
    await page.getByTestId('suspend-confirm').click();
    await expect(page.getByTestId('platform-status-1')).toHaveAttribute('data-status', 'suspended');

    await expect(page.getByTestId('platform-impersonate-1')).toHaveCount(0);
    await page.getByTestId('platform-reactivate-1').click();
    await page.getByTestId('reactivate-confirm').click();
    await expect(page.getByTestId('platform-impersonate-1')).toBeVisible();
  });

  test('an owner never sees the banner', async ({ page }) => {
    await registerAndEnter(page);

    await expect(page.getByTestId('impersonation-banner')).toHaveCount(0);
  });
});
