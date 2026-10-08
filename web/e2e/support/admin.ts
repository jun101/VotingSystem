import { expect, type Page } from '@playwright/test';
import { uniqueEmail } from './mail';

/*
 * Helpers for the browser tests of the admin area (slice 03 and after).
 * Part of the acceptance harness.
 */

export const PASSWORD = 'un mot de passe long et sûr';

/** Registers an institution through the page and lands on the dashboard, in the page's language. */
export async function registerAndEnter(
  page: Page,
  options: { email?: string; institution?: string; name?: string } = {},
): Promise<{ email: string; institution: string; name: string }> {
  const email = options.email ?? uniqueEmail();
  const institution = options.institution ?? 'Collège Les Flamboyants';
  const name = options.name ?? 'Marie Joseph';

  await page.goto('/register');
  await page.getByTestId('register-institution-name').fill(institution);
  await page.getByTestId('register-name').fill(name);
  await page.getByTestId('register-email').fill(email);
  await page.getByTestId('register-password').fill(PASSWORD);
  await page.getByTestId('register-submit').click();
  await expect(page).toHaveURL(/\/admin$/);

  return { email, institution, name };
}

/** Opens the user menu of the top bar, then signs out. */
export async function signOut(page: Page): Promise<void> {
  await page.getByTestId('user-menu').click();
  await page.getByTestId('signout-button').click();
}

/** True when the screen is narrower than the `lg` breakpoint (1024 px): the menu is a drawer. */
export function isPhone(page: Page): boolean {
  return (page.viewportSize()?.width ?? 1280) < 1024;
}

/** Opens the drawer on a phone; does nothing on a desktop, where the menu is always there. */
export async function openMenu(page: Page): Promise<void> {
  if (isPhone(page)) {
    await page.getByTestId('menu-button').click();
    await expect(page.getByTestId('menu-drawer')).toBeVisible();
  }
}
