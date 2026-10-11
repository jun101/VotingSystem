import { expect, type Page } from '@playwright/test';
import { PASSWORD } from './admin';

/*
 * Slice 18 helpers. The platform admin of the browser tests is created by `make e2e` before the run, with
 * `platform:create-admin e2e-admin@platform.test --password-from-env=PLATFORM_ADMIN_PASSWORD` (the same password as
 * every test account). Part of the acceptance harness.
 */

export const ADMIN_EMAIL = 'e2e-admin@platform.test';
export const ADMIN_PASSWORD = PASSWORD;

/** Signs in through the login page. Does not wait for a destination. */
export async function signInAs(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('login-email').fill(email);
  await page.getByTestId('login-password').fill(password);
  await page.getByTestId('login-submit').click();
}

/** Signs in as the platform admin and waits for the platform screen. */
export async function enterPlatform(page: Page): Promise<void> {
  await signInAs(page, ADMIN_EMAIL, ADMIN_PASSWORD);
  await expect(page).toHaveURL(/\/platform$/);
  await expect(page.getByTestId('platform-page')).toBeVisible();
}

/** Looks an institution up by name; the first card is then the one searched for. */
export async function findInstitution(page: Page, name: string): Promise<void> {
  await page.getByTestId('platform-search').fill(name);
  await expect(page.getByTestId('platform-name-1')).toHaveText(name);
}
