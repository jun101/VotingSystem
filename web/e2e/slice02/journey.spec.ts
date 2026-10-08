import { expect, test, type Page } from '@playwright/test';
import { signOut } from '../support/admin';
import { linkIn, uniqueEmail, waitForMails } from '../support/mail';

/*
 * Slice 02 — the whole path of a person, in French and in English:
 * register, verify the email, sign out, sign in, forget the password, reset it.
 * docs/slices/02-sign-up-and-sign-in.md, "Goal" and "Copy the tests rely on".
 * Updated in slice 03: the placeholder is now the dashboard of the admin shell, and the
 * sign-out button is in the user menu.
 */

const PASSWORD = 'un mot de passe long et sûr';
const NEW_PASSWORD = 'un autre mot de passe long';

const languages = [
  {
    locale: 'fr-FR',
    lang: 'fr',
    verify: /vérif/i,
    reset: /initialis/i,
    signOut: 'Se déconnecter',
    signIn: 'Se connecter',
  },
  {
    locale: 'en-US',
    lang: 'en',
    verify: /verify/i,
    reset: /reset/i,
    signOut: 'Sign out',
    signIn: 'Sign in',
  },
];

async function register(page: Page, email: string, institution = 'Collège Les Flamboyants') {
  await page.goto('/register');
  await page.getByTestId('register-institution-name').fill(institution);
  await page.getByTestId('register-name').fill('Marie Joseph');
  await page.getByTestId('register-email').fill(email);
  await page.getByTestId('register-password').fill(PASSWORD);
  await page.getByTestId('register-submit').click();
}

async function signIn(page: Page, email: string, password: string) {
  await page.goto('/login');
  await page.getByTestId('login-email').fill(email);
  await page.getByTestId('login-password').fill(password);
  await page.getByTestId('login-submit').click();
}

for (const language of languages) {
  test.describe(`the whole path in ${language.lang}`, () => {
    test.use({ locale: language.locale });

    test('register, verify, sign out, sign in, reset the password [FR-INST-01, FR-INST-04]', async ({ page }) => {
      const email = uniqueEmail();

      // Register: the owner lands on the admin placeholder, signed in, email not verified.
      await register(page, email);
      await expect(page).toHaveURL(/\/admin$/);
      await expect(page.locator('html')).toHaveAttribute('lang', language.lang);
      await expect(page.getByTestId('dashboard-welcome')).toContainText('Marie Joseph');
      await expect(page.getByTestId('dashboard-institution')).toContainText('Collège Les Flamboyants');
      await expect(page.getByTestId('verify-banner')).toBeVisible();

      // The verification email, in the right language, with a link that holds a token only.
      const [verification] = await waitForMails(email, 1);
      expect(verification.subject).toMatch(language.verify);
      const verifyLink = linkIn(verification, '/verify-email');
      expect(verifyLink).not.toBeNull();
      expect(verifyLink).not.toContain(encodeURIComponent(email));

      // The link verifies the address.
      await page.goto(verifyLink!);
      await expect(page.getByTestId('verify-state')).toHaveAttribute('data-state', 'success');
      await page.goto('/admin');
      await expect(page.getByTestId('dashboard-welcome')).toBeVisible();
      await expect(page.getByTestId('verify-banner')).toHaveCount(0);

      // Sign out, then back in.
      await signOut(page);
      await expect(page).toHaveURL(/\/login$/);
      await page.goto('/admin');
      await expect(page).toHaveURL(/\/login$/);

      await signIn(page, email, PASSWORD);
      await expect(page).toHaveURL(/\/admin$/);
      await expect(page.getByTestId('dashboard-welcome')).toContainText('Marie Joseph');
      await signOut(page);
      await expect(page).toHaveURL(/\/login$/);

      // Forgot the password.
      await page.goto('/forgot-password');
      await page.getByTestId('forgot-email').fill(email);
      await page.getByTestId('forgot-submit').click();
      await expect(page.getByTestId('forgot-sent')).toBeVisible();

      const mails = await waitForMails(email, 2);
      const resetMail = mails.find((mail) => linkIn(mail, '/reset-password') !== null);
      expect(resetMail).toBeDefined();
      expect(resetMail!.subject).toMatch(language.reset);
      const resetLink = linkIn(resetMail!, '/reset-password')!;
      expect(resetLink).not.toContain(encodeURIComponent(email));

      await page.goto(resetLink);
      await page.getByTestId('reset-password').fill(NEW_PASSWORD);
      await page.getByTestId('reset-submit').click();
      await expect(page).toHaveURL(/\/login/);
      await expect(page.getByTestId('login-notice')).toBeVisible();

      // The old password no longer works; the new one does.
      await signIn(page, email, PASSWORD);
      await expect(page.getByTestId('form-error')).toBeVisible();
      await expect(page).toHaveURL(/\/login/);

      await signIn(page, email, NEW_PASSWORD);
      await expect(page).toHaveURL(/\/admin$/);
    });
  });
}

test('the verification email can be sent again from the banner [FR-INST-01]', async ({ page }) => {
  const email = uniqueEmail();
  await register(page, email);
  await expect(page.getByTestId('verify-banner')).toBeVisible();
  await waitForMails(email, 1);

  await page.getByTestId('resend-button').click();
  await expect(page.getByTestId('resend-done')).toBeVisible();

  const mails = await waitForMails(email, 2);
  const [first, second] = mails.map((mail) => linkIn(mail, '/verify-email'));
  expect(first).not.toBeNull();
  expect(second).not.toBeNull();
  expect(second).not.toBe(first);

  // The first link no longer works; the new one does.
  await page.goto(first!);
  await expect(page.getByTestId('verify-state')).toHaveAttribute('data-state', 'invalid');
  await page.goto(second!);
  await expect(page.getByTestId('verify-state')).toHaveAttribute('data-state', 'success');
});

test('sends a signed-in person away from the sign-in pages and a visitor away from /admin [FR-INST-04]', async ({ page }) => {
  await page.goto('/admin');
  await expect(page).toHaveURL(/\/login$/);

  await register(page, uniqueEmail());
  await expect(page).toHaveURL(/\/admin$/);

  for (const path of ['/login', '/register', '/forgot-password']) {
    await page.goto(path);
    await expect(page, path).toHaveURL(/\/admin$/);
  }
});
