import { expect, test } from '@playwright/test';
import { openMenu, registerAndEnter } from '../support/admin';
import { pngBuffer } from '../support/images';
import { uniqueEmail } from '../support/mail';
import { acceptInNewBrowser, invite, openInstitution } from '../support/team';

/*
 * Slice 04 — the profile, the logo and the public address on screen A14.
 * docs/slices/04-profile-and-users.md, parts 3 and 3b. API: docs/api/institution/.
 */

test.describe('the profile', () => {
  test('is reached in two clicks from the dashboard, and shows what was registered [FR-INST-02, FR-NAV-04]', async ({ page }) => {
    await registerAndEnter(page, { institution: 'Collège Étoile du Matin' });

    await openInstitution(page);

    await expect(page.getByTestId('top-bar-title')).toHaveText('Établissement');
    await expect(page.getByTestId('profile-card')).toBeVisible();
    await expect(page.getByTestId('users-card')).toBeVisible();
    await expect(page.getByTestId('profile-name')).toHaveValue('Collège Étoile du Matin');
    await expect(page.getByTestId('profile-timezone')).toHaveValue('America/Port-au-Prince');
    await expect(page.getByTestId('profile-language')).toHaveValue('fr');
    // Cards, not a table.
    await expect(page.locator('table')).toHaveCount(0);
  });

  test('is saved, shown again after a reload, and updates the menu [FR-INST-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/institution');

    await page.getByTestId('profile-name').fill('Collège Les Hirondelles');
    await page.getByTestId('profile-type').selectOption('school');
    await page.getByTestId('profile-city').fill('Port-au-Prince');
    await page.getByTestId('profile-phone').fill('+509 2222 0000');
    await page.getByTestId('profile-address').fill('12 rue des Palmistes');
    await page.getByTestId('profile-contact-email').fill('direction@hirondelles.example');
    await page.getByTestId('profile-description').fill('École secondaire.');
    await page.getByTestId('profile-timezone').selectOption('America/New_York');
    await page.getByTestId('profile-language').selectOption('en');
    await page.getByTestId('profile-save').click();

    await expect(page.getByTestId('profile-saved')).toBeVisible();

    await page.reload();
    await expect(page.getByTestId('profile-name')).toHaveValue('Collège Les Hirondelles');
    await expect(page.getByTestId('profile-type')).toHaveValue('school');
    await expect(page.getByTestId('profile-city')).toHaveValue('Port-au-Prince');
    await expect(page.getByTestId('profile-phone')).toHaveValue('+509 2222 0000');
    await expect(page.getByTestId('profile-address')).toHaveValue('12 rue des Palmistes');
    await expect(page.getByTestId('profile-contact-email')).toHaveValue('direction@hirondelles.example');
    await expect(page.getByTestId('profile-description')).toHaveValue('École secondaire.');
    await expect(page.getByTestId('profile-timezone')).toHaveValue('America/New_York');
    await expect(page.getByTestId('profile-language')).toHaveValue('en');

    // The institution's name is in the side menu too.
    await openMenu(page);
    await expect(page.getByTestId('side-menu')).toContainText('Collège Les Hirondelles');
  });

  test('shows the error under each field and keeps what was typed [FR-INST-02]', async ({ page }) => {
    await registerAndEnter(page, { institution: 'Collège Les Flamboyants' });
    await page.goto('/admin/institution');

    await page.getByTestId('profile-name').fill('');
    await page.getByTestId('profile-phone').fill('509 abc');
    await page.getByTestId('profile-contact-email').fill('not an address');
    await page.getByTestId('profile-save').click();

    await expect(page.getByTestId('profile-name-error')).toBeVisible();
    await expect(page.getByTestId('profile-phone-error')).toBeVisible();
    await expect(page.getByTestId('profile-contact-email-error')).toBeVisible();
    await expect(page.getByTestId('profile-saved')).toHaveCount(0);
    await expect(page.getByTestId('profile-phone')).toHaveValue('509 abc');

    // The error is read from the message files, never an error code.
    await expect(page.getByTestId('profile-phone-error')).not.toContainText('format');
    await expect(page.getByTestId('profile-phone-error')).not.toContainText('validation_failed');

    // Nothing was saved.
    await page.reload();
    await expect(page.getByTestId('profile-name')).toHaveValue('Collège Les Flamboyants');
  });

  test('counts the characters of the short description [FR-INST-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/institution');

    await page.getByTestId('profile-description').fill('x'.repeat(120));
    await expect(page.getByTestId('profile-card')).toContainText('120');
    await expect(page.getByTestId('profile-card')).toContainText('500');
  });
});

test.describe('the public address', () => {
  test('is shown with a copy button and says the page is not available yet [FR-INST-02]', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => undefined);
    await registerAndEnter(page);
    await page.goto('/admin/institution');

    const address = page.getByTestId('public-address');
    await expect(address).toBeVisible();
    await expect(address).toContainText(/\/institutions\/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/);
    await expect(page.getByTestId('public-address-copy')).toBeVisible();
    await expect(page.getByTestId('institution-page')).toContainText('bientôt');
  });
});

test.describe('the logo', () => {
  test('is uploaded, shown at once, and replaces the initials in the side menu [FR-INST-02, FR-CAND-04]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/institution');
    await expect(page.getByTestId('logo-initials')).toBeVisible();
    await expect(page.getByTestId('logo-preview')).toHaveCount(0);

    await page.getByTestId('logo-input').setInputFiles({ name: 'mon logo.png', mimeType: 'image/png', buffer: pngBuffer(300, 150) });

    const preview = page.getByTestId('logo-preview');
    await expect(preview).toBeVisible();
    await expect(preview).toHaveAttribute('src', /^\/media\/[0-9a-f-]{36}-(160|480)\.webp$/);
    await expect.poll(() => preview.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    await expect(page.getByTestId('logo-initials')).toHaveCount(0);

    // The menu follows, without a reload.
    await openMenu(page);
    const small = page.getByTestId('menu-institution-logo');
    await expect(small).toBeVisible();
    await expect(small).toHaveAttribute('src', /-64\.webp$/);

    // And after a reload.
    await page.reload();
    await expect(page.getByTestId('logo-preview')).toBeVisible();
  });

  test('is served by the proxy as an immutable WebP, and /media/ lists nothing [FR-CAND-04]', async ({ page, request }) => {
    await registerAndEnter(page);
    await page.goto('/admin/institution');
    await page.getByTestId('logo-input').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: pngBuffer() });
    const src = await page.getByTestId('logo-preview').getAttribute('src');
    expect(src).toMatch(/^\/media\//);

    const file = await request.get(src!);
    expect(file.status()).toBe(200);
    expect(file.headers()['content-type']).toBe('image/webp');
    expect(file.headers()['cache-control']).toContain('immutable');
    expect(file.headers()['x-content-type-options']).toBe('nosniff');
    expect((await file.body()).subarray(0, 4).toString('ascii')).toBe('RIFF');

    const directory = await request.get('/media/');
    expect(directory.status()).not.toBe(200);
    // An answer that is not a file is never cached for a year.
    expect(directory.headers()['cache-control'] ?? '').not.toContain('immutable');
    expect((await request.get('/media/anything.svg')).status()).toBe(404);
    expect((await request.get('/media/anything.html')).status()).toBe(404);
    const missing = await request.get('/media/00000000-0000-4000-8000-000000000000-64.webp');
    expect(missing.status()).toBe(404);
  });

  test('is removed, and the initials come back in the page and the menu [FR-INST-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/institution');
    await page.getByTestId('logo-input').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: pngBuffer() });
    await expect(page.getByTestId('logo-preview')).toBeVisible();

    await page.getByTestId('logo-remove').click();

    await expect(page.getByTestId('logo-preview')).toHaveCount(0);
    await expect(page.getByTestId('logo-initials')).toBeVisible();
    await openMenu(page);
    await expect(page.getByTestId('menu-institution-logo')).toHaveCount(0);
  });

  test('refuses a file that is not a picture, with a message, and keeps the old logo [NFR-SEC-06]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/institution');
    await page.getByTestId('logo-input').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: pngBuffer() });
    const before = await page.getByTestId('logo-preview').getAttribute('src');

    await page.getByTestId('logo-input').setInputFiles({ name: 'faux.png', mimeType: 'image/png', buffer: Buffer.from('<?php echo 1; ?>') });

    await expect(page.getByTestId('logo-error')).toBeVisible();
    await expect(page.getByTestId('logo-error')).not.toContainText('file_type_not_allowed');
    await expect(page.getByTestId('logo-preview')).toHaveAttribute('src', before!);
  });
});

test.describe('a manager', () => {
  test('sees the profile and the logo but can change nothing, and has no users card [FR-INST-03]', async ({ page, browser }) => {
    await registerAndEnter(page, { institution: 'Collège Les Flamboyants' });
    const email = uniqueEmail('manager');
    const link = await invite(page, email, 'manager');
    const manager = await acceptInNewBrowser(browser, link);

    await manager.goto('/admin/institution');
    await expect(manager.getByTestId('institution-page')).toBeVisible();
    await expect(manager.getByTestId('profile-name')).toHaveValue('Collège Les Flamboyants');
    await expect(manager.getByTestId('profile-name')).toBeDisabled();
    await expect(manager.getByTestId('profile-save')).toHaveCount(0);
    await expect(manager.getByTestId('logo-input')).toHaveCount(0);
    await expect(manager.getByTestId('logo-remove')).toHaveCount(0);
    await expect(manager.getByTestId('users-card')).toHaveCount(0);
    await expect(manager.getByTestId('users-manager-note')).toContainText('Seul un propriétaire');
    await expect(manager.getByTestId('invite-open')).toHaveCount(0);
    await manager.context().close();
  });

  test('is told the same thing in English when the page is in English [NFR-UX-01]', async ({ page, browser }) => {
    await registerAndEnter(page);
    const link = await invite(page, uniqueEmail('manager'), 'manager');
    const manager = await acceptInNewBrowser(browser, link);
    await openMenu(manager);
    await manager.getByTestId('language-switch').click();
    await expect(manager.getByTestId('language-switch')).toHaveAttribute('data-language', 'en');

    await manager.goto('/admin/institution');

    await expect(manager.getByTestId('users-manager-note')).toContainText('Only an owner');
    await expect(manager.getByTestId('top-bar-title')).toHaveText('Institution');
    await manager.context().close();
  });
});

test('shows no number of the database anywhere on the page [NFR-SEC-08]', async ({ page }) => {
  await registerAndEnter(page);
  await page.goto('/admin/institution');
  await expect(page.getByTestId('institution-page')).toBeVisible();

  const html = await page.content();
  expect(html).not.toMatch(/institution_id|logo_file|"id":\s*\d+/);
});
