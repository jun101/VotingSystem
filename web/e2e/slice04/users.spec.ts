import { expect, test } from '@playwright/test';
import { openMenu, PASSWORD, registerAndEnter } from '../support/admin';
import { linkIn, uniqueEmail, waitForMails } from '../support/mail';
import { acceptInNewBrowser, invite } from '../support/team';

/*
 * Slice 04 — inviting, accepting, cancelling and removing users on screen A14.
 * docs/slices/04-profile-and-users.md, parts 3 and 3b. API: docs/api/users/ and
 * docs/api/auth/POST-auth-accept-invitation.md.
 */

test.describe('inviting', () => {
  test('an owner invites a manager, who reads the email, chooses a password and enters [FR-INST-03]', async ({ page, browser }) => {
    await registerAndEnter(page, { institution: 'Collège Les Flamboyants', name: 'Marie Joseph' });
    const email = uniqueEmail('manager');

    const link = await invite(page, email, 'manager');

    // The owner sees the pending invitation as a card.
    const card = page.locator('[data-testid^="invitation-email-"]', { hasText: email });
    await expect(card).toBeVisible();
    await expect(page.locator('[data-testid^="invitation-status-"]').first()).toContainText('en attente');

    // The email: in French, names the institution, never the inviter, a token and nothing else in the link.
    const [mail] = await waitForMails(email, 1);
    expect(mail!.text + mail!.html).toContain('Les Flamboyants');
    expect(mail!.text + mail!.html).not.toContain('Marie Joseph');
    expect(link).toMatch(/^\/accept-invitation\?token=[0-9a-f]{64}$/);

    // The invited person accepts in another browser.
    const manager = await acceptInNewBrowser(browser, link, 'Jean Pierre');
    await expect(manager.getByTestId('dashboard-institution')).toContainText('Collège Les Flamboyants');
    await openMenu(manager);
    await expect(manager.getByTestId('menu-user-name')).toHaveText('Jean Pierre');
    await expect(manager.getByTestId('menu-user-role')).toHaveText('Gestionnaire');
    // Verified at once: no verification banner.
    await expect(manager.getByTestId('verify-banner')).toHaveCount(0);

    // The owner now sees a user card and no invitation card.
    await page.reload();
    await expect(page.locator('[data-testid^="user-email-"]', { hasText: email })).toBeVisible();
    await expect(page.locator('[data-testid^="invitation-email-"]')).toHaveCount(0);
    await manager.context().close();
  });

  test('an owner can invite another owner, who then manages the users too [FR-INST-03]', async ({ page, browser }) => {
    await registerAndEnter(page);
    const link = await invite(page, uniqueEmail('owner'), 'owner');

    const second = await acceptInNewBrowser(browser, link, 'Paul Second');
    await openMenu(second);
    await expect(second.getByTestId('menu-user-role')).toHaveText('Propriétaire');
    await second.goto('/admin/institution');
    await expect(second.getByTestId('users-card')).toBeVisible();
    await expect(second.getByTestId('invite-open')).toBeVisible();
    await second.context().close();
  });

  test('the link works once [FR-INST-03]', async ({ page, browser }) => {
    await registerAndEnter(page);
    const link = await invite(page, uniqueEmail('manager'));
    const first = await acceptInNewBrowser(browser, link);
    await first.context().close();

    const context = await browser.newContext();
    const again = await context.newPage();
    await again.goto(link);
    await again.getByTestId('accept-name').fill('Quelqu\'un d\'autre');
    await again.getByTestId('accept-password').fill(PASSWORD);
    await again.getByTestId('accept-submit').click();

    await expect(again.getByTestId('accept-invalid')).toBeVisible();
    await expect(again).not.toHaveURL(/\/admin/);
    await context.close();
  });

  test('a link that never existed shows a clear page, with a way to sign in [FR-INST-03]', async ({ page }) => {
    await page.goto(`/accept-invitation?token=${'a'.repeat(64)}`);
    await page.getByTestId('accept-name').fill('Personne');
    await page.getByTestId('accept-password').fill(PASSWORD);
    await page.getByTestId('accept-submit').click();

    await expect(page.getByTestId('accept-invalid')).toBeVisible();
    await expect(page.getByTestId('accept-invalid')).not.toContainText('not_found');
    await expect(page.getByTestId('accept-invalid').getByRole('link')).toHaveAttribute('href', '/login');
  });

  test('a short password is refused under the field, and the person can try again [NFR-SEC-02]', async ({ page, browser }) => {
    await registerAndEnter(page);
    const link = await invite(page, uniqueEmail('manager'));

    const context = await browser.newContext({ locale: 'fr-FR' });
    const guest = await context.newPage();
    await guest.goto(link);
    await guest.getByTestId('accept-name').fill('Jean Pierre');
    await guest.getByTestId('accept-password').fill('court');
    await guest.getByTestId('accept-submit').click();

    await expect(guest.getByTestId('accept-password-error')).toBeVisible();
    await expect(guest).not.toHaveURL(/\/admin/);

    await guest.getByTestId('accept-password').fill(PASSWORD);
    await guest.getByTestId('accept-submit').click();
    await expect(guest).toHaveURL(/\/admin$/);
    await context.close();
  });

  test('inviting an address that already has an account is refused under the field [FR-INST-03]', async ({ page }) => {
    const { email } = await registerAndEnter(page);
    await page.goto('/admin/institution');

    await page.getByTestId('invite-open').click();
    await page.getByTestId('invite-email').fill(email);
    await page.getByTestId('invite-submit').click();

    await expect(page.getByTestId('invite-email-error')).toBeVisible();
    await expect(page.getByTestId('invite-email-error')).not.toContainText('taken');
    await expect(page.locator('[data-testid^="invitation-email-"]')).toHaveCount(0);
  });

  test('inviting a wrong address is refused under the field and sends nothing [FR-INST-03]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/institution');

    await page.getByTestId('invite-open').click();
    await page.getByTestId('invite-email').fill('pas une adresse');
    await page.getByTestId('invite-submit').click();

    await expect(page.getByTestId('invite-email-error')).toBeVisible();
  });

  test('inviting the same address again replaces the first invitation: one card, the old link dead [FR-INST-03]', async ({ page, browser }) => {
    await registerAndEnter(page);
    const email = uniqueEmail('manager');
    const first = await invite(page, email, 'manager');

    await page.goto('/admin/institution');
    await page.getByTestId('invite-open').click();
    await page.getByTestId('invite-email').fill(email);
    await page.getByTestId('invite-role').selectOption('owner');
    await page.getByTestId('invite-submit').click();
    await expect(page.locator('[data-testid^="invitation-role-"], [data-testid^="invitation-email-"]').first()).toBeVisible();

    const mails = await waitForMails(email, 2);
    const second = linkIn(mails[1]!, '/accept-invitation');
    expect(second).not.toBe(first);
    await expect(page.locator('[data-testid^="invitation-email-"]', { hasText: email })).toHaveCount(1);

    const context = await browser.newContext();
    const old = await context.newPage();
    await old.goto(first);
    await old.getByTestId('accept-name').fill('Jean');
    await old.getByTestId('accept-password').fill(PASSWORD);
    await old.getByTestId('accept-submit').click();
    await expect(old.getByTestId('accept-invalid')).toBeVisible();
    await context.close();
  });
});

test.describe('cancelling', () => {
  test('an owner cancels an invitation: the card goes and the link stops working [FR-INST-03]', async ({ page, browser }) => {
    await registerAndEnter(page);
    const email = uniqueEmail('manager');
    const link = await invite(page, email);

    await page.locator('[data-testid^="invitation-cancel-"]').first().click();

    await expect(page.locator('[data-testid^="invitation-email-"]')).toHaveCount(0);
    await page.reload();
    await expect(page.locator('[data-testid^="invitation-email-"]')).toHaveCount(0);

    const context = await browser.newContext();
    const guest = await context.newPage();
    await guest.goto(link);
    await guest.getByTestId('accept-name').fill('Jean');
    await guest.getByTestId('accept-password').fill(PASSWORD);
    await guest.getByTestId('accept-submit').click();
    await expect(guest.getByTestId('accept-invalid')).toBeVisible();
    await context.close();
  });
});

test.describe('removing', () => {
  test('an owner removes a manager after a confirmation that names them; the manager is turned away [FR-INST-03]', async ({ page, browser }) => {
    await registerAndEnter(page);
    const email = uniqueEmail('manager');
    const manager = await acceptInNewBrowser(browser, await invite(page, email), 'Jean Pierre');
    await page.reload();

    const card = page.locator('[data-testid^="user-card-"]', { hasText: email });
    await expect(card).toBeVisible();

    // Cancel first: nothing happens.
    await card.getByTestId(/^user-remove-\d+$/).click();
    await expect(page.getByTestId('user-remove-dialog')).toBeVisible();
    await expect(page.getByTestId('user-remove-dialog')).toContainText('Jean Pierre');
    await page.getByTestId('user-remove-cancel').click();
    await expect(page.getByTestId('user-remove-dialog')).toHaveCount(0);
    await expect(card).toBeVisible();

    // Confirm: the card goes.
    await card.getByTestId(/^user-remove-\d+$/).click();
    await page.getByTestId('user-remove-confirm').click();
    await expect(page.locator('[data-testid^="user-email-"]', { hasText: email })).toHaveCount(0);

    // The removed person is turned away at the next page, and cannot sign in again.
    await manager.goto('/admin');
    await expect(manager).toHaveURL(/\/login/);
    await manager.getByTestId('login-email').fill(email);
    await manager.getByTestId('login-password').fill(PASSWORD);
    await manager.getByTestId('login-submit').click();
    await expect(manager.getByTestId('form-error')).toBeVisible();
    await manager.context().close();
  });

  test('the only owner cannot be removed: a clear message, and the card stays [FR-INST-03]', async ({ page }) => {
    await registerAndEnter(page, { name: 'Marie Joseph' });
    await page.goto('/admin/institution');
    await expect(page.getByTestId('user-you')).toBeVisible();

    await page.getByTestId(/^user-remove-\d+$/).click();
    await page.getByTestId('user-remove-confirm').click();

    await expect(page.getByTestId('user-remove-error')).toBeVisible();
    await expect(page.getByTestId('user-remove-error')).not.toContainText('last_owner');
    await page.getByTestId('user-remove-cancel').click();
    await expect(page.getByTestId('user-you')).toBeVisible();
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin$/);
  });

  test('an owner can remove themself when another owner exists, and is signed out [FR-INST-03]', async ({ page, browser }) => {
    await registerAndEnter(page);
    const second = await acceptInNewBrowser(browser, await invite(page, uniqueEmail('owner'), 'owner'), 'Paul Second');
    await second.context().close();
    await page.reload();

    await page.locator('[data-testid^="user-card-"]', { has: page.getByTestId('user-you') }).getByTestId(/^user-remove-\d+$/).click();
    await page.getByTestId('user-remove-confirm').click();

    await expect(page).toHaveURL(/\/login/);
  });
});

test.describe('two institutions', () => {
  test('each owner sees only their own users and invitations [FR-INST-05]', async ({ browser }) => {
    const a = await (await browser.newContext({ locale: 'fr-FR' })).newPage();
    const b = await (await browser.newContext({ locale: 'fr-FR' })).newPage();
    const aEmail = uniqueEmail('owner-a');
    const bEmail = uniqueEmail('owner-b');
    await registerAndEnter(a, { email: aEmail, institution: 'Institution A', name: 'Alice Owner' });
    await registerAndEnter(b, { email: bEmail, institution: 'Institution B', name: 'Bruno Owner' });
    const invitedA = uniqueEmail('guest-a');
    await invite(a, invitedA);

    await b.goto('/admin/institution');
    await expect(b.getByTestId('profile-name')).toHaveValue('Institution B');
    const text = await b.getByTestId('institution-page').innerText();
    expect(text).not.toContain(aEmail);
    expect(text).not.toContain(invitedA);
    expect(text).not.toContain('Alice Owner');
    await expect(b.locator('[data-testid^="user-card-"]')).toHaveCount(1);

    await a.context().close();
    await b.context().close();
  });
});
