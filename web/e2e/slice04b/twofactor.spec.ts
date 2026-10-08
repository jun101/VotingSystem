import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { PASSWORD, registerAndEnter } from '../support/admin';
import { uniqueEmail } from '../support/mail';
import { acceptInNewBrowser, invite } from '../support/team';
import { totp, wrongCode } from '../support/totp';
import { enableTwoFactor, openAccount, readRecoveryCodes, signOutAndSignIn } from '../support/twofactor';

/*
 * Slice 04b — two-factor authentication on screen "Mon compte", at sign-in, and the owner's reset.
 * docs/slices/04b-two-factor.md, parts 2 and 2b. API: docs/api/auth/ (two-factor files).
 */

const current = (secret: string) => totp(secret);

test.describe('setting it up', () => {
  test('is reached in two clicks and shows the QR code, the secret and the steps [FR-INST-04]', async ({ page }) => {
    await registerAndEnter(page);

    await openAccount(page);

    await expect(page.getByTestId('top-bar-title')).toHaveText('Mon compte');
    await expect(page.getByTestId('security-card')).toBeVisible();
    await expect(page.getByTestId('two-factor-status')).toContainText('Désactivée');

    await page.getByTestId('two-factor-setup-open').click();
    await page.getByTestId('two-factor-password').fill(PASSWORD);
    await page.getByTestId('two-factor-setup-submit').click();

    // The QR code is drawn in the browser and has a text alternative; the secret is for manual entry.
    const qr = page.getByTestId('two-factor-qr');
    await expect(qr).toBeVisible();
    expect((await qr.getAttribute('aria-label')) ?? (await qr.getAttribute('alt')) ?? (await qr.locator('title').textContent()) ?? '').not.toBe('');
    await expect(page.getByTestId('two-factor-secret')).toHaveText(/^([A-Z2-7]{4}\s){7}[A-Z2-7]{4}$/);

    // Nothing leaves for another site.
    const foreign = await page.evaluate(() =>
      performance.getEntriesByType('resource').map((entry) => entry.name).filter((name) => !name.startsWith(location.origin)),
    );
    expect(foreign).toEqual([]);
  });

  test('refuses a wrong password and a wrong code under their fields, then turns it on and shows the recovery codes once [FR-INST-04]', async ({ page }) => {
    await registerAndEnter(page);
    await openAccount(page);
    await page.getByTestId('two-factor-setup-open').click();

    await page.getByTestId('two-factor-password').fill('pas le bon mot de passe');
    await page.getByTestId('two-factor-setup-submit').click();
    await expect(page.getByTestId('two-factor-password-error')).toBeVisible();
    await expect(page.getByTestId('two-factor-password-error')).not.toContainText('incorrect');
    await expect(page.getByTestId('two-factor-secret')).toHaveCount(0);

    await page.getByTestId('two-factor-password').fill(PASSWORD);
    await page.getByTestId('two-factor-setup-submit').click();
    const secret = (await page.getByTestId('two-factor-secret').innerText()).replace(/\s/g, '');

    await page.getByTestId('two-factor-code').fill(wrongCode(secret));
    await page.getByTestId('two-factor-confirm').click();
    await expect(page.getByTestId('two-factor-code-error')).toBeVisible();
    await expect(page.getByTestId('two-factor-code-error')).not.toContainText('invalid');
    await expect(page.getByTestId('recovery-codes')).toHaveCount(0);

    await page.getByTestId('two-factor-code').fill(totp(secret));
    await page.getByTestId('two-factor-confirm').click();

    const codes = await readRecoveryCodes(page);
    expect(codes).toHaveLength(8);
    expect(new Set(codes).size).toBe(8);
    for (const code of codes) expect(code).toMatch(/^[a-z0-9]{5}-[a-z0-9]{5}$/);

    // "Terminer" waits for the person to say they kept the codes.
    await expect(page.getByTestId('recovery-done')).toBeDisabled();
    await page.getByTestId('recovery-saved').check();
    await expect(page.getByTestId('recovery-done')).toBeEnabled();
    await page.getByTestId('recovery-done').click();

    await expect(page.getByTestId('two-factor-status')).toContainText('Activée');
    await expect(page.getByTestId('recovery-codes-left')).toContainText('8');
    // The codes are gone from the page, and from a reload.
    await expect(page.getByTestId('recovery-codes')).toHaveCount(0);
    await page.reload();
    await expect(page.getByTestId('recovery-codes')).toHaveCount(0);
    await expect(page.getByTestId('two-factor-status')).toContainText('Activée');
  });

  test('offers the recovery codes as a text file that holds nothing else [FR-INST-04]', async ({ page }) => {
    await registerAndEnter(page, { institution: 'Collège Les Flamboyants' });
    await openAccount(page);
    await page.getByTestId('two-factor-setup-open').click();
    await page.getByTestId('two-factor-password').fill(PASSWORD);
    await page.getByTestId('two-factor-setup-submit').click();
    const secret = (await page.getByTestId('two-factor-secret').innerText()).replace(/\s/g, '');
    await page.getByTestId('two-factor-code').fill(totp(secret));
    await page.getByTestId('two-factor-confirm').click();
    const codes = await readRecoveryCodes(page);

    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('recovery-download').click()]);

    expect(download.suggestedFilename()).toBe('codes-de-recuperation.txt');
    const content = await readFile(await download.path(), 'utf8');
    for (const code of codes) expect(content).toContain(code);
    expect(content).not.toContain('Flamboyants');
    expect(content).not.toContain('@');
  });
});

test.describe('signing in', () => {
  test('asks for a code after the password, refuses a wrong one, and enters with the right one [FR-INST-04]', async ({ page }) => {
    const { email } = await registerAndEnter(page);
    const { secret } = await enableTwoFactor(page, PASSWORD, current);

    await signOutAndSignIn(page, email);
    await expect(page.getByTestId('challenge-code')).toBeFocused();

    await page.getByTestId('challenge-code').fill(wrongCode(secret));
    await page.getByTestId('challenge-submit').click();
    await expect(page.getByTestId('challenge-code-error')).toBeVisible();
    await expect(page.getByTestId('challenge-code-error')).not.toContainText('invalid');
    await expect(page).toHaveURL(/\/login$/);

    // The period after the one used at confirmation is accepted.
    await page.getByTestId('challenge-code').fill(totp(secret, 1));
    await page.getByTestId('challenge-submit').click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  });

  test('nothing of the admin area opens between the password and the code [FR-INST-04]', async ({ page }) => {
    const { email } = await registerAndEnter(page);
    await enableTwoFactor(page, PASSWORD, current);
    await signOutAndSignIn(page, email);

    await page.goto('/admin');

    await expect(page).toHaveURL(/\/login/);
  });

  test('accepts a recovery code, once, and the account page counts what is left [FR-INST-04]', async ({ page }) => {
    const { email } = await registerAndEnter(page);
    const { codes } = await enableTwoFactor(page, PASSWORD, current);
    await signOutAndSignIn(page, email);

    await page.getByTestId('challenge-recovery-toggle').click();
    await page.getByTestId('challenge-recovery-code').fill(codes[0]!.toUpperCase());
    await page.getByTestId('challenge-submit').click();
    await expect(page).toHaveURL(/\/admin$/);

    await openAccount(page);
    await expect(page.getByTestId('recovery-codes-left')).toContainText('7');

    // The same code does not work a second time.
    await signOutAndSignIn(page, email);
    await page.getByTestId('challenge-recovery-toggle').click();
    await page.getByTestId('challenge-recovery-code').fill(codes[0]!);
    await page.getByTestId('challenge-submit').click();
    await expect(page.getByTestId('challenge-recovery-code-error')).toBeVisible();
    await page.getByTestId('challenge-recovery-code').fill(codes[1]!);
    await page.getByTestId('challenge-submit').click();
    await expect(page).toHaveURL(/\/admin$/);
  });

  test('goes back to the password after five wrong codes, with a message [FR-INST-04]', async ({ page }) => {
    const { email } = await registerAndEnter(page);
    const { secret } = await enableTwoFactor(page, PASSWORD, current);
    await signOutAndSignIn(page, email);

    for (let i = 0; i < 5; i++) {
      await page.getByTestId('challenge-code').fill(wrongCode(secret));
      await page.getByTestId('challenge-submit').click();
      await expect(page.getByTestId('challenge-code-error')).toBeVisible();
    }
    await page.getByTestId('challenge-code').fill(totp(secret, 1));
    await page.getByTestId('challenge-submit').click();

    await expect(page.getByTestId('challenge-expired')).toBeVisible();
    await expect(page.getByTestId('login-form')).toBeVisible();
    await expect(page).not.toHaveURL(/\/admin/);
  });

  test('"Annuler" returns to the password step [FR-INST-04]', async ({ page }) => {
    const { email } = await registerAndEnter(page);
    await enableTwoFactor(page, PASSWORD, current);
    await signOutAndSignIn(page, email);

    await page.getByTestId('challenge-cancel').click();

    await expect(page.getByTestId('login-form')).toBeVisible();
    await expect(page.getByTestId('challenge-form')).toHaveCount(0);
    // The focus lands on the password field (the email is already filled), not on the page body.
    await expect(page.getByTestId('login-password')).toBeFocused();
  });
});

test.describe('turning it off and renewing the codes', () => {
  test('turning it off asks for the password and a current code, and the next sign-in asks for the password only [FR-INST-04]', async ({ page }) => {
    const { email } = await registerAndEnter(page);
    const { secret } = await enableTwoFactor(page, PASSWORD, current);
    await openAccount(page);

    await page.getByTestId('two-factor-disable-open').click();
    await expect(page.getByTestId('two-factor-disable-dialog')).toBeVisible();

    // The password alone is not enough.
    await page.getByTestId('two-factor-password').fill(PASSWORD);
    await page.getByTestId('two-factor-disable-confirm').click();
    await expect(page.getByTestId('two-factor-code-error')).toBeVisible();
    await expect(page.getByTestId('two-factor-status')).toContainText('Activée');

    // A wrong password with a right code is refused under the password field.
    await page.getByTestId('two-factor-password').fill('pas le bon mot de passe');
    await page.getByTestId('two-factor-code').fill(totp(secret, 1));
    await page.getByTestId('two-factor-disable-confirm').click();
    await expect(page.getByTestId('two-factor-password-error')).toBeVisible();
    await expect(page.getByTestId('two-factor-status')).toContainText('Activée');

    // A wrong code with the right password is refused under the code field.
    await page.getByTestId('two-factor-password').fill(PASSWORD);
    await page.getByTestId('two-factor-code').fill(wrongCode(secret));
    await page.getByTestId('two-factor-disable-confirm').click();
    await expect(page.getByTestId('two-factor-code-error')).toBeVisible();
    await expect(page.getByTestId('two-factor-code-error')).not.toContainText('invalid');
    await expect(page.getByTestId('two-factor-status')).toContainText('Activée');

    await page.getByTestId('two-factor-code').fill(totp(secret, 1));
    await page.getByTestId('two-factor-disable-confirm').click();
    await expect(page.getByTestId('two-factor-disable-dialog')).toHaveCount(0);
    await expect(page.getByTestId('two-factor-status')).toContainText('Désactivée');

    await page.getByTestId('user-menu').click();
    await page.getByTestId('signout-button').click();
    await page.getByTestId('login-email').fill(email);
    await page.getByTestId('login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await expect(page).toHaveURL(/\/admin$/);
  });

  test('cancelling the dialog changes nothing [FR-INST-04]', async ({ page }) => {
    await registerAndEnter(page);
    await enableTwoFactor(page, PASSWORD, current);
    await openAccount(page);

    await page.getByTestId('two-factor-disable-open').click();
    await page.getByTestId('two-factor-disable-cancel').click();

    await expect(page.getByTestId('two-factor-disable-dialog')).toHaveCount(0);
    await expect(page.getByTestId('two-factor-status')).toContainText('Activée');
  });

  test('renewing the codes shows eight new ones, and an old one no longer works [FR-INST-04]', async ({ page }) => {
    const { email } = await registerAndEnter(page);
    const { codes: oldCodes } = await enableTwoFactor(page, PASSWORD, current);
    await openAccount(page);

    await page.getByTestId('two-factor-codes-open').click();
    await page.getByTestId('two-factor-password').fill(PASSWORD);
    // The second factor can be a recovery code, as at sign-in.
    await page.getByTestId('two-factor-recovery-toggle').click();
    await page.getByTestId('two-factor-recovery-code').fill(oldCodes[7]!);
    await page.getByTestId('two-factor-codes-confirm').click();

    const fresh = await readRecoveryCodes(page);
    expect(fresh).toHaveLength(8);
    expect(fresh.filter((code) => oldCodes.includes(code))).toEqual([]);
    await page.getByTestId('recovery-saved').check();
    await page.getByTestId('recovery-done').click();

    await signOutAndSignIn(page, email);
    await page.getByTestId('challenge-recovery-toggle').click();
    await page.getByTestId('challenge-recovery-code').fill(oldCodes[0]!);
    await page.getByTestId('challenge-submit').click();
    await expect(page.getByTestId('challenge-recovery-code-error')).toBeVisible();
    await page.getByTestId('challenge-recovery-code').fill(fresh[0]!);
    await page.getByTestId('challenge-submit').click();
    await expect(page).toHaveURL(/\/admin$/);
  });
});

test.describe('an owner and the users', () => {
  test('sees who has two-factor, and turns off a manager\'s who lost the phone and the codes [FR-INST-03, FR-INST-04]', async ({ page, browser }) => {
    await registerAndEnter(page);
    const managerEmail = uniqueEmail('manager');
    const manager = await acceptInNewBrowser(browser, await invite(page, managerEmail, 'manager'), 'Jean Pierre');
    await enableTwoFactor(manager, PASSWORD, current);

    await page.goto('/admin/institution');
    const chip = page.locator('[data-testid^="user-two-factor-"][data-enabled="true"]');
    await expect(page.locator('[data-testid^="user-two-factor-"][data-enabled="true"]')).toHaveCount(1);
    await expect(page.locator('[data-testid^="user-two-factor-"][data-enabled="false"]')).toHaveCount(1);
    await expect(chip.first()).toContainText('Double authentification activée');

    await page.getByTestId(/^user-reset-two-factor-\d+$/).click();
    await expect(page.getByTestId('user-reset-dialog')).toContainText('Jean Pierre');
    await page.getByTestId('user-reset-cancel').click();
    await expect(page.getByTestId('user-reset-dialog')).toHaveCount(0);
    await expect(page.locator('[data-testid^="user-two-factor-"][data-enabled="true"]')).toHaveCount(1);

    await page.getByTestId(/^user-reset-two-factor-\d+$/).click();
    // The owner's own password is asked: a wrong one is refused under the field and nothing changes.
    await page.getByTestId('user-reset-password').fill('pas le bon mot de passe');
    await page.getByTestId('user-reset-confirm').click();
    await expect(page.getByTestId('user-reset-password-error')).toBeVisible();
    await expect(page.getByTestId('user-reset-password-error')).not.toContainText('incorrect');
    await expect(page.locator('[data-testid^="user-two-factor-"][data-enabled="true"]')).toHaveCount(1);

    await page.getByTestId('user-reset-password').fill(PASSWORD);
    await page.getByTestId('user-reset-confirm').click();
    await expect(page.locator('[data-testid^="user-two-factor-"][data-enabled="true"]')).toHaveCount(0);
    // The keyboard focus is not lost to the page body when the dialog closes and the button is gone.
    await expect
      .poll(() => page.evaluate(() => document.activeElement !== document.body && document.activeElement !== null))
      .toBe(true);

    // The manager signs in with the password only.
    await manager.getByTestId('user-menu').click();
    await manager.getByTestId('signout-button').click();
    await manager.getByTestId('login-email').fill(managerEmail);
    await manager.getByTestId('login-password').fill(PASSWORD);
    await manager.getByTestId('login-submit').click();
    await expect(manager).toHaveURL(/\/admin$/);
    await manager.context().close();
  });

  test('has no reset on their own card, and a manager sees none [FR-INST-04]', async ({ page, browser }) => {
    await registerAndEnter(page);
    await enableTwoFactor(page, PASSWORD, current);
    await page.goto('/admin/institution');
    await expect(page.locator('[data-testid^="user-card-"]', { has: page.getByTestId('user-you') }).getByTestId(/^user-reset-two-factor-\d+$/)).toHaveCount(0);

    const manager = await acceptInNewBrowser(browser, await invite(page, uniqueEmail('manager'), 'manager'));
    await manager.goto('/admin/institution');
    await expect(manager.getByTestId(/^user-reset-two-factor-\d+$/)).toHaveCount(0);
    await manager.context().close();
  });

  test('asks an owner who has two-factor on their own code too, to turn off a manager\'s [FR-INST-04, NFR-SEC-01]', async ({ page, browser }) => {
    await registerAndEnter(page);
    const managerEmail = uniqueEmail('manager');
    const manager = await acceptInNewBrowser(browser, await invite(page, managerEmail, 'manager'), 'Jean Pierre');
    await enableTwoFactor(manager, PASSWORD, current);
    const owner = await enableTwoFactor(page, PASSWORD, current);

    await page.goto('/admin/institution');
    await page.getByTestId(/^user-reset-two-factor-\d+$/).click();
    await page.getByTestId('user-reset-password').fill(PASSWORD);

    // No code: refused under the code field.
    await page.getByTestId('user-reset-confirm').click();
    await expect(page.getByTestId('user-reset-code-error')).toBeVisible();
    await expect(page.locator('[data-testid^="user-two-factor-"][data-enabled="true"]')).toHaveCount(2);

    // A recovery code of the owner works.
    await page.getByTestId('user-reset-recovery-toggle').click();
    await page.getByTestId('user-reset-recovery-code').fill(owner.codes[0]!);
    await page.getByTestId('user-reset-confirm').click();
    await expect(page.locator('[data-testid^="user-two-factor-"][data-enabled="true"]')).toHaveCount(1);
    await manager.context().close();
  });
});
