import { expect, type Browser, type Page } from '@playwright/test';
import { PASSWORD } from './admin';
import { linkIn, waitForMails } from './mail';

/*
 * Helpers for the browser tests of the institution page and its users (slice 04).
 * Part of the acceptance harness.
 */

export type Role = 'owner' | 'manager';

/** Opens the page of the institution from the dashboard through the menu: two clicks at most. */
export async function openInstitution(page: Page): Promise<void> {
  const phone = (page.viewportSize()?.width ?? 1280) < 1024;
  if (phone) await page.getByTestId('menu-button').click();
  await page.getByTestId('menu-link-institution').click();
  await expect(page).toHaveURL(/\/admin\/institution$/);
  await expect(page.getByTestId('institution-page')).toBeVisible();
}

/**
 * The owner invites an address from the page of the institution; waits for the invitation card
 * and for the email; returns the path and query of the link in it.
 */
export async function invite(page: Page, email: string, role: Role = 'manager'): Promise<string> {
  await page.goto('/admin/institution');
  await page.getByTestId('invite-open').click();
  await page.getByTestId('invite-email').fill(email);
  await page.getByTestId('invite-role').selectOption(role);
  await page.getByTestId('invite-submit').click();
  await expect(page.locator('[data-testid^="invitation-email-"]', { hasText: email })).toBeVisible();

  const [mail] = await waitForMails(email, 1);
  const link = linkIn(mail!, '/accept-invitation');
  if (link === null) throw new Error('No invitation link in the email');

  return link;
}

/**
 * Opens the link in a fresh browser (another person), chooses a name and the shared test
 * password, and lands on the admin area. Returns that person's page.
 */
export async function acceptInNewBrowser(
  browser: Browser,
  link: string,
  name = 'Jean Pierre',
  locale = 'fr-FR',
): Promise<Page> {
  const context = await browser.newContext({ locale, baseURL: process.env.BASE_URL ?? 'http://localhost:8080' });
  const page = await context.newPage();
  await page.goto(link);
  await page.getByTestId('accept-name').fill(name);
  await page.getByTestId('accept-password').fill(PASSWORD);
  await page.getByTestId('accept-submit').click();
  await expect(page).toHaveURL(/\/admin$/);

  return page;
}
