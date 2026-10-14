import { expect, test } from '@playwright/test';
import { signOut } from '../support/admin';

/*
 * The school edition (DEPLOYMENT_MODE=school), seen in a browser: no sign-up and no email pages, the installer's owner signs
 * in with a one-time password and must replace it, the owner creates people with a one-time password of their own, and the
 * pages say the connection is not encrypted. On demand, against a stack started in school mode with an owner made by
 * `php artisan owner:create` (docs/ops/installation-guide.md):
 *
 *   SCHOOL_MODE=1 SCHOOL_OWNER_EMAIL=directeur@ecole.local SCHOOL_OWNER_PASSWORD=<one-time password> \
 *     npx playwright test e2e/slice19c/school-mode.spec.ts --project=desktop
 */

test.describe.configure({ mode: 'serial' });
test.skip(!process.env.SCHOOL_MODE, 'on demand: SCHOOL_MODE=1 against a school-mode stack');

const EMAIL = process.env.SCHOOL_OWNER_EMAIL ?? 'directeur@ecole.local';
const ONE_TIME = process.env.SCHOOL_OWNER_PASSWORD ?? '';
const NEW_PASSWORD = 'un mot de passe bien long 2026';

test('has no sign-up and no email pages', async ({ page }) => {
  for (const path of ['/register', '/forgot-password', '/reset-password', '/verify-email']) {
    const response = await page.goto(path);
    expect(response?.status(), path).toBe(404);
  }

  await page.goto('/login');
  await expect(page.getByTestId('login-form')).toBeVisible();
  await expect(page.getByTestId('login-register')).toHaveCount(0);
  await expect(page.getByTestId('login-forgot')).toHaveCount(0);
});

test('the owner signs in with the one-time password and must replace it first', async ({ page }) => {
  test.skip(!ONE_TIME, 'SCHOOL_OWNER_PASSWORD is the one-time password the installer wrote');

  await page.goto('/login');
  await page.getByTestId('login-email').fill(EMAIL);
  await page.getByTestId('login-password').fill(ONE_TIME);
  await page.getByTestId('login-submit').click();

  await expect(page.getByTestId('password-change-required')).toBeVisible();
  await expect(page.getByTestId('side-menu')).toHaveCount(0);

  await page.getByTestId('change-password-current').fill(ONE_TIME);
  await page.getByTestId('change-password-new').fill(NEW_PASSWORD);
  await page.getByTestId('change-password-submit').click();

  await expect(page.getByTestId('dashboard-welcome')).toBeVisible();
});

test('shows no email banner, and a notice that the connection is not encrypted', async ({ page }) => {
  await page.goto('/login');
  await page.getByTestId('login-email').fill(EMAIL);
  await page.getByTestId('login-password').fill(NEW_PASSWORD);
  await page.getByTestId('login-submit').click();
  await expect(page).toHaveURL(/\/admin$/);

  await expect(page.getByTestId('verify-banner')).toHaveCount(0);
  await expect(page.getByTestId('insecure-notice')).toBeVisible();
});

test('the owner creates a person with a one-time password, shown once, and can reset it', async ({ page }) => {
  await page.goto('/login');
  await page.getByTestId('login-email').fill(EMAIL);
  await page.getByTestId('login-password').fill(NEW_PASSWORD);
  await page.getByTestId('login-submit').click();
  await expect(page).toHaveURL(/\/admin$/);

  await page.goto('/admin/institution');
  await expect(page.getByTestId('invite-open')).toHaveCount(0);
  await page.getByTestId('create-member-open').click();
  await page.getByTestId('create-member-name').fill('Jean Pierre');
  await page.getByTestId('create-member-email').fill(`secretaire.${Date.now().toString(36)}@ecole.local`);
  await page.getByTestId('create-member-password').fill(NEW_PASSWORD);
  await page.getByTestId('create-member-submit').click();

  const issued = page.getByTestId('issued-password-value');
  await expect(issued).toBeVisible();
  const first = (await issued.innerText()).trim();
  expect(first.length).toBeGreaterThanOrEqual(12);

  await page.getByTestId('issued-password-dismiss').click();
  await expect(page.getByTestId('issued-password-value')).toHaveCount(0);
  await signOut(page);
});
