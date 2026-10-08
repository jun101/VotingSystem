import { expect, test } from '@playwright/test';
import { openMenu, PASSWORD, registerAndEnter, signOut } from '../support/admin';
import { uniqueEmail } from '../support/mail';

/*
 * Slice 03 — the language switch of the admin area, stored on the user.
 * docs/slices/03-admin-shell-and-tenant-isolation.md, part 4 ("Language switch",
 * "Language at load") and rule 9. PATCH /api/v1/auth/me.
 */

test.describe('registered in French', () => {
  test.use({ locale: 'fr-FR' });

  test('switches to English without reloading the page, and back [NFR-UX-01]', async ({ page }) => {
    await registerAndEnter(page);
    await openMenu(page);
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    await expect(page.getByTestId('language-switch')).toHaveAttribute('data-language', 'fr');

    await page.evaluate(() => {
      (window as unknown as { __kept: number }).__kept = 1;
    });

    await page.getByTestId('language-switch').click();

    await expect(page.getByTestId('language-switch')).toHaveAttribute('data-language', 'en');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByTestId('top-bar-title')).toHaveText('Dashboard');
    await expect(page.getByTestId('menu-link-audit')).toContainText('Audit log');
    await expect(page.getByTestId('menu-user-role')).toHaveText('Owner');
    expect(await page.evaluate(() => (window as unknown as { __kept?: number }).__kept)).toBe(1);

    await page.getByTestId('language-switch').click();
    await expect(page.getByTestId('language-switch')).toHaveAttribute('data-language', 'fr');
    await expect(page.getByTestId('top-bar-title')).toHaveText('Tableau de bord');
  });

  test('is kept after a reload, and after signing out and in again [NFR-UX-01]', async ({ page }) => {
    const { email } = await registerAndEnter(page);
    await openMenu(page);
    await page.getByTestId('language-switch').click();
    await expect(page.getByTestId('language-switch')).toHaveAttribute('data-language', 'en');

    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByTestId('top-bar-title')).toHaveText('Dashboard');

    // The pages before sign-in still follow the browser (French here); the admin follows the user.
    await signOut(page);
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    await page.getByTestId('login-email').fill(email);
    await page.getByTestId('login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByTestId('top-bar-title')).toHaveText('Dashboard');
  });

  test('is the language of every admin page, not only the first [NFR-UX-01]', async ({ page }) => {
    await registerAndEnter(page);
    await openMenu(page);
    await page.getByTestId('language-switch').click();
    await expect(page.getByTestId('language-switch')).toHaveAttribute('data-language', 'en');

    for (const [path, title] of [
      ['/admin/elections', 'Elections'],
      ['/admin/institution', 'Institution'],
      ['/admin/audit', 'Audit log'],
    ] as const) {
      await page.goto(path);
      await expect(page.locator('html')).toHaveAttribute('lang', 'en');
      await expect(page.getByTestId('top-bar-title')).toHaveText(title);
      // The institution page is built in slice 04 and has no "not available yet" notice.
      if (path !== '/admin/institution') {
        await expect(page.getByTestId('coming-soon')).not.toContainText('disponible');
      }
    }
  });

  test('keeps the old language and says so when the change fails [NFR-UX-01]', async ({ page }) => {
    await registerAndEnter(page);
    await openMenu(page);

    await page.route('**/api/v1/auth/me', (route) =>
      route.request().method() === 'PATCH'
        ? route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":{"code":"server_error","message":"x","reference":"r"}}' })
        : route.continue(),
    );
    await page.getByTestId('language-switch').click();

    await expect(page.getByTestId('language-error')).toBeVisible();
    await expect(page.getByTestId('language-switch')).toHaveAttribute('data-language', 'fr');
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    await expect(page.getByTestId('top-bar-title')).toHaveText('Tableau de bord');

    // Nothing was stored.
    await page.unroute('**/api/v1/auth/me');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  });

  test('sends one change request, with the CSRF token, and no token in the browser storage [NFR-SEC-04]', async ({ page }) => {
    await registerAndEnter(page);
    await openMenu(page);
    const requests: { method: string; headers: Record<string, string>; body: string | null }[] = [];
    page.on('request', (request) => {
      if (request.url().includes('/api/v1/auth/me') && request.method() === 'PATCH') {
        requests.push({ method: request.method(), headers: request.headers(), body: request.postData() });
      }
    });

    await page.getByTestId('language-switch').click();
    await expect(page.getByTestId('language-switch')).toHaveAttribute('data-language', 'en');

    expect(requests).toHaveLength(1);
    expect(requests[0].headers['x-xsrf-token']).toBeTruthy();
    expect(JSON.parse(requests[0].body ?? '{}')).toEqual({ language: 'en' });
    expect(await page.evaluate(() => JSON.stringify([localStorage, sessionStorage]))).not.toMatch(/xsrf|token/i);
  });
});

test.describe('registered in English', () => {
  test.use({ locale: 'en-US' });

  test('starts in English, and French can be chosen [NFR-UX-01]', async ({ page }) => {
    await registerAndEnter(page);
    await openMenu(page);
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByTestId('top-bar-title')).toHaveText('Dashboard');
    await expect(page.getByTestId('menu-election-card')).toContainText('No election selected');

    await page.getByTestId('language-switch').click();
    await expect(page.getByTestId('language-switch')).toHaveAttribute('data-language', 'fr');
    await expect(page.getByTestId('top-bar-title')).toHaveText('Tableau de bord');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  });
});

test('one person\'s choice does not change another person\'s [FR-INST-05]', async ({ browser }, testInfo) => {
  const options = { ...(testInfo.project.use as Parameters<typeof browser.newContext>[0]), locale: 'fr-FR' };
  const first = await (await browser.newContext(options)).newPage();
  const second = await (await browser.newContext(options)).newPage();

  await registerAndEnter(first, { email: uniqueEmail('un') });
  await registerAndEnter(second, { email: uniqueEmail('deux') });

  await openMenu(first);
  await first.getByTestId('language-switch').click();
  await expect(first.getByTestId('language-switch')).toHaveAttribute('data-language', 'en');

  await second.reload();
  await expect(second.locator('html')).toHaveAttribute('lang', 'fr');
  await expect(second.getByTestId('top-bar-title')).toHaveText('Tableau de bord');

  await first.context().close();
  await second.context().close();
});

test('a user who registered with another browser language than the page keeps the stored one [NFR-UX-01]', async ({ browser }, testInfo) => {
  const options = testInfo.project.use as Parameters<typeof browser.newContext>[0];

  // Registered and switched to English in a French browser...
  const french = await (await browser.newContext({ ...options, locale: 'fr-FR' })).newPage();
  const { email } = await registerAndEnter(french, { email: uniqueEmail('stored') });
  await openMenu(french);
  await french.getByTestId('language-switch').click();
  await expect(french.getByTestId('language-switch')).toHaveAttribute('data-language', 'en');
  await french.context().close();

  // ...then signing in from an English browser, then a French one: always English.
  for (const locale of ['en-US', 'fr-FR']) {
    const page = await (await browser.newContext({ ...options, locale })).newPage();
    await page.goto('/login');
    await page.getByTestId('login-email').fill(email);
    await page.getByTestId('login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByTestId('top-bar-title')).toHaveText('Dashboard');
    await page.context().close();
  }
});
