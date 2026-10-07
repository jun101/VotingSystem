import { expect, test } from '@playwright/test';
import { uniqueEmail } from '../support/mail';

/*
 * Slice 02 — what the forms do when something is wrong, and how they can be used.
 */

const PASSWORD = 'un mot de passe long et sûr';

test.describe('register', () => {
  test('shows each error next to its field and puts the focus on the first one [NFR-UX-03]', async ({ page }) => {
    await page.goto('/register');
    await page.getByTestId('register-institution-name').fill('Collège');
    await page.getByTestId('register-name').fill('Marie');
    await page.getByTestId('register-email').fill('not-an-address');
    await page.getByTestId('register-password').fill('court');
    await page.getByTestId('register-submit').click();

    for (const field of ['email', 'password']) {
      await expect(page.getByTestId(`field-error-${field}`)).toBeVisible();
      await expect(page.getByTestId(`register-${field}`)).toHaveAttribute('aria-invalid', 'true');
    }
    await expect(page).toHaveURL(/\/register$/);
    await expect(page.getByTestId('register-email')).toBeFocused();
    await expect(page.getByTestId('register-institution-name')).not.toHaveAttribute('aria-invalid', 'true');
  });

  test('says an email already has an account [FR-INST-01]', async ({ page, browser }) => {
    const email = uniqueEmail();

    const first = await browser.newPage();
    await first.goto('/register');
    await first.getByTestId('register-institution-name').fill('Collège Un');
    await first.getByTestId('register-name').fill('Marie');
    await first.getByTestId('register-email').fill(email);
    await first.getByTestId('register-password').fill(PASSWORD);
    await first.getByTestId('register-submit').click();
    await expect(first).toHaveURL(/\/admin$/);
    await first.close();

    await page.goto('/register');
    await page.getByTestId('register-institution-name').fill('Collège Deux');
    await page.getByTestId('register-name').fill('Paul');
    await page.getByTestId('register-email').fill(email.toUpperCase());
    await page.getByTestId('register-password').fill(PASSWORD);
    await page.getByTestId('register-submit').click();

    await expect(page.getByTestId('field-error-email')).toBeVisible();
    await expect(page).toHaveURL(/\/register$/);
  });

  test('can be filled and sent with the keyboard alone [NFR-UX-03]', async ({ page }) => {
    await page.goto('/register');
    await page.getByTestId('register-institution-name').focus();

    await page.keyboard.type('Collège Clavier');
    await page.keyboard.press('Tab');
    await page.keyboard.type('Marie Joseph');
    await page.keyboard.press('Tab');
    await page.keyboard.type(uniqueEmail());
    await page.keyboard.press('Tab');
    await page.keyboard.type(PASSWORD);
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL(/\/admin$/);
  });

  test('can show and hide the password [NFR-UX-03]', async ({ page }) => {
    await page.goto('/register');
    const field = page.getByTestId('register-password');

    await expect(field).toHaveAttribute('type', 'password');
    await page.getByTestId('register-password-toggle').click();
    await expect(field).toHaveAttribute('type', 'text');
    await page.getByTestId('register-password-toggle').click();
    await expect(field).toHaveAttribute('type', 'password');
  });
});

test.describe('sign-in', () => {
  test('says the email or the password is wrong, and never puts them in the address [NFR-SEC-02]', async ({ page }) => {
    await page.goto('/login');
    await page.getByTestId('login-email').fill('nobody@example.test');
    await page.getByTestId('login-password').fill('a wrong password, long enough');
    await page.getByTestId('login-submit').click();

    await expect(page.getByTestId('form-error')).toBeVisible();
    await expect(page.getByTestId('form-error')).toHaveAttribute('role', 'alert');
    await expect(page).toHaveURL(/\/login$/);
    expect(page.url()).not.toContain('nobody');
    expect(page.url()).not.toContain('password');
  });

  test('has links to the other sign-in pages [NFR-UX-03]', async ({ page }) => {
    await page.goto('/login');

    await page.getByTestId('login-forgot').click();
    await expect(page).toHaveURL(/\/forgot-password$/);

    await page.goto('/login');
    await page.getByTestId('login-register').click();
    await expect(page).toHaveURL(/\/register$/);
  });
});

test.describe('links that do not work', () => {
  test('a verification link that is unknown says so [FR-INST-01]', async ({ page }) => {
    await page.goto(`/verify-email?token=${'a'.repeat(64)}`);

    await expect(page.getByTestId('verify-state')).toHaveAttribute('data-state', 'invalid');
  });

  test('a verification page without a token says so [FR-INST-01]', async ({ page }) => {
    await page.goto('/verify-email');

    await expect(page.getByTestId('verify-state')).toHaveAttribute('data-state', 'invalid');
  });

  test('a reset link that is unknown says so and offers a new one [FR-INST-04]', async ({ page }) => {
    await page.goto(`/reset-password?token=${'b'.repeat(64)}`);
    await page.getByTestId('reset-password').fill('un nouveau mot de passe long');
    await page.getByTestId('reset-submit').click();

    await expect(page.getByTestId('reset-invalid')).toBeVisible();
    await expect(page.getByTestId('reset-invalid').getByRole('link')).toHaveAttribute('href', '/forgot-password');
  });

  test('asking for a reset link says the same for an address nobody uses [FR-INST-04]', async ({ page }) => {
    await page.goto('/forgot-password');
    await page.getByTestId('forgot-email').fill(uniqueEmail('nobody'));
    await page.getByTestId('forgot-submit').click();

    await expect(page.getByTestId('forgot-sent')).toBeVisible();
  });
});

test.describe('what the browser keeps', () => {
  test('keeps the session in an HttpOnly cookie and nothing secret in the page storage [NFR-SEC-04]', async ({ page, context }) => {
    await page.goto('/register');
    await page.getByTestId('register-institution-name').fill('Collège');
    await page.getByTestId('register-name').fill('Marie');
    await page.getByTestId('register-email').fill(uniqueEmail());
    await page.getByTestId('register-password').fill(PASSWORD);
    await page.getByTestId('register-submit').click();
    await expect(page).toHaveURL(/\/admin$/);

    const cookies = await context.cookies();
    const session = cookies.find((cookie) => cookie.httpOnly);
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');

    expect(session, 'a session cookie that scripts cannot read').toBeDefined();
    expect(session!.sameSite).toBe('Lax');
    expect(xsrf, 'the CSRF cookie').toBeDefined();
    expect(xsrf!.httpOnly).toBe(false);

    const storage = await page.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }));
    expect(storage).not.toMatch(/[0-9a-f]{40,}/i);
    expect(storage.toLowerCase()).not.toContain('token');
    expect(storage.toLowerCase()).not.toContain('password');
  });
});
