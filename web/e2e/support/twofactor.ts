import { expect, type Page } from '@playwright/test';
import { signOut } from './admin';
import { PASSWORD } from './admin';

/*
 * Helpers for the browser tests of two-factor authentication (slice 04b).
 * Part of the acceptance harness.
 */

/** Opens "Mon compte" from the user menu of the top bar: two clicks from any admin page. */
export async function openAccount(page: Page): Promise<void> {
  await page.getByTestId('user-menu').click();
  await page.getByTestId('user-menu-account').click();
  await expect(page).toHaveURL(/\/admin\/account$/);
  await expect(page.getByTestId('account-page')).toBeVisible();
}

/** The eight codes shown in the list, in order. */
export async function readRecoveryCodes(page: Page): Promise<string[]> {
  const list = page.getByTestId('recovery-codes');
  await expect(list).toBeVisible();
  const codes: string[] = [];
  for (let n = 1; n <= 8; n++) {
    codes.push((await page.getByTestId(`recovery-code-${n}`).innerText()).trim());
  }
  return codes;
}

/**
 * Turns two-factor on through the account page, the way a person does. Returns the secret (as
 * typed into an authenticator application) and the recovery codes. The first code is the one of
 * the current period, so a sign-in right after must use the next one: `totp(secret, 1)`.
 */
export async function enableTwoFactor(
  page: Page,
  password = PASSWORD,
  currentCode: (secret: string) => string,
): Promise<{ secret: string; codes: string[] }> {
  await openAccount(page);
  await page.getByTestId('two-factor-setup-open').click();
  await page.getByTestId('two-factor-password').fill(password);
  await page.getByTestId('two-factor-setup-submit').click();

  const secret = ((await page.getByTestId('two-factor-secret').innerText()) ?? '').replace(/\s/g, '');
  expect(secret).toMatch(/^[A-Z2-7]{32}$/);

  await page.getByTestId('two-factor-code').fill(currentCode(secret));
  await page.getByTestId('two-factor-confirm').click();

  const codes = await readRecoveryCodes(page);
  await page.getByTestId('recovery-saved').check();
  await page.getByTestId('recovery-done').click();
  await expect(page.getByTestId('two-factor-status')).toContainText(/Activée|On/);

  return { secret, codes };
}

/** Signs out and starts signing in again: stops at the code step. */
export async function signOutAndSignIn(page: Page, email: string, password = PASSWORD): Promise<void> {
  await signOut(page);
  await expect(page).toHaveURL(/\/login$/);
  await page.getByTestId('login-email').fill(email);
  await page.getByTestId('login-password').fill(password);
  await page.getByTestId('login-submit').click();
  await expect(page.getByTestId('challenge-form')).toBeVisible();
}
