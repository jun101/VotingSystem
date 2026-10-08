import { expect, test, type Page } from '@playwright/test';
import { openMenu, PASSWORD, registerAndEnter, signOut } from '../support/admin';
import { uniqueEmail } from '../support/mail';

/*
 * Slice 03 — the admin shell: side menu, top bar, dashboard, guards.
 * docs/slices/03-admin-shell-and-tenant-isolation.md, part 4 and 4b.
 */

const entries = [
  { key: 'dashboard', path: '/admin', fr: 'Tableau de bord', en: 'Dashboard' },
  { key: 'elections', path: '/admin/elections', fr: 'Élections', en: 'Elections' },
  { key: 'institution', path: '/admin/institution', fr: 'Établissement', en: 'Institution' },
  { key: 'audit', path: '/admin/audit', fr: "Journal d'audit", en: 'Audit log' },
] as const;

const cards = ['open-election', 'todo', 'figures', 'activity', 'latest'];

async function currentLinks(page: Page): Promise<string[]> {
  return page
    .getByTestId('side-menu')
    .locator('[aria-current="page"]')
    .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-testid') ?? ''));
}

test.describe('the shell after sign-up [FR-NAV-02, FR-NAV-03]', () => {
  test('shows the side menu, the top bar and the empty dashboard', async ({ page }) => {
    const { institution, name } = await registerAndEnter(page);

    await expect(page.getByTestId('admin-shell')).toBeVisible();
    await expect(page.getByTestId('top-bar')).toBeVisible();
    await expect(page.getByTestId('top-bar-title')).toHaveText('Tableau de bord');
    await expect(page.getByTestId('dashboard')).toBeVisible();
    await expect(page.getByTestId('dashboard-welcome')).toContainText(name);
    await expect(page.getByTestId('dashboard-institution')).toContainText(institution);

    // The five blocks of the dashboard, each with an honest empty message; cards, no table.
    for (const card of cards) {
      await expect(page.getByTestId(`dashboard-card-${card}`)).toBeVisible();
    }
    await expect(page.getByTestId('dashboard').locator('table')).toHaveCount(0);
    await expect(page.getByTestId('dashboard-create-election')).toHaveAttribute('href', '/admin/elections/new');

    // The verification banner of slice 02 is still there.
    await expect(page.getByTestId('verify-banner')).toBeVisible();
  });

  test('has the whole side menu, with an empty election card', async ({ page }) => {
    const { institution, name } = await registerAndEnter(page);
    await openMenu(page);

    await expect(page.getByTestId('side-menu')).toContainText(institution);
    await expect(page.getByTestId('menu-search')).toBeVisible();
    await expect(page.getByTestId('menu-new-election')).toHaveAttribute('href', '/admin/elections/new');
    for (const entry of entries) {
      await expect(page.getByTestId(`menu-link-${entry.key}`)).toHaveAttribute('href', entry.path);
    }
    await expect(page.getByTestId('menu-user-name')).toHaveText(name);
    await expect(page.getByTestId('menu-user-role')).toHaveText('Propriétaire');

    // No election yet: the card says so and links to the list; the election section is absent.
    await expect(page.getByTestId('menu-election-card')).toContainText('Aucune élection choisie');
    await expect(page.getByTestId('menu-election-card').locator('a')).toHaveAttribute('href', '/admin/elections');
    await expect(page.getByTestId('menu-election-section')).toHaveCount(0);
  });

  test('opens every entry inside the shell and marks the current one [FR-NAV-02]', async ({ page }) => {
    await registerAndEnter(page);

    for (const entry of entries) {
      await openMenu(page);
      await page.getByTestId(`menu-link-${entry.key}`).click();
      await expect(page).toHaveURL(new RegExp(`${entry.path}$`));
      await expect(page.getByTestId('admin-shell')).toBeVisible();
      await expect(page.getByTestId('top-bar-title')).toHaveText(entry.fr);
      await expect(page.locator('h1')).toHaveCount(1);

      // The dashboard is real since slice 03, the institution page since slice 04, the elections since slice 05.
      if (entry.key !== 'dashboard' && entry.key !== 'institution' && entry.key !== 'elections') {
        await expect(page.getByTestId('coming-soon')).toBeVisible();
      }

      await openMenu(page);
      expect(await currentLinks(page)).toEqual([`menu-link-${entry.key}`]);
      // Close the drawer again on a phone (nothing to close on a desktop).
      await page.keyboard.press('Escape');
    }
  });

  test('the "new election" button opens a page of the shell, with Élections current [FR-NAV-02]', async ({ page }) => {
    await registerAndEnter(page);
    await openMenu(page);
    await page.getByTestId('menu-new-election').click();

    await expect(page).toHaveURL(/\/admin\/elections\/new$/);
    await expect(page.getByTestId('admin-shell')).toBeVisible();
    await expect(page.getByTestId('election-form')).toBeVisible();
    await openMenu(page);
    expect(await currentLinks(page)).toEqual(['menu-link-elections']);
  });

  test('reaches any page from any other in at most three clicks [FR-NAV-02]', async ({ page }) => {
    await registerAndEnter(page);

    // From the deepest page the menu has, to every other page: one click on the menu.
    await page.goto('/admin/elections/new');
    for (const entry of entries) {
      await openMenu(page);
      await page.getByTestId(`menu-link-${entry.key}`).click();
      await expect(page).toHaveURL(new RegExp(`${entry.path}$`));
    }
  });

  test('has no link, address or data value that is a number [NFR-SEC-08]', async ({ page }) => {
    await registerAndEnter(page);

    for (const path of ['/admin', '/admin/elections', '/admin/institution', '/admin/audit']) {
      await page.goto(path);
      const html = await page.content();
      const hrefs = await page.locator('[href]').evaluateAll((nodes) => nodes.map((n) => n.getAttribute('href') ?? ''));
      const data = await page.locator('*').evaluateAll((nodes) =>
        nodes.flatMap((n) => Array.from(n.attributes).filter((a) => a.name.startsWith('data-') && a.name !== 'data-testid').map((a) => a.value)),
      );

      for (const value of [...hrefs, page.url()]) {
        expect(value, `${path}: ${value}`).not.toMatch(/\/\d+(\/|\?|$)/);
      }
      for (const value of data) {
        expect(value, `${path}: data value ${value}`).not.toMatch(/^\d+$/);
      }
      expect(html).not.toMatch(/"id":\s*\d+/);
      expect(html).not.toMatch(/institution_id|user_id/);
    }
  });
});

test.describe('the "go to" search [FR-NAV-02]', () => {
  test('filters the entries as the person types, ignoring case and accents, and opens the first match with Enter', async ({ page }) => {
    await registerAndEnter(page);
    await openMenu(page);

    await page.getByTestId('menu-search').fill('ELECTION');
    await expect(page.getByTestId('menu-link-elections')).toBeVisible();
    await expect(page.getByTestId('menu-link-audit')).toBeHidden();
    await page.getByTestId('menu-search').press('Enter');
    await expect(page).toHaveURL(/\/admin\/elections$/);

    await openMenu(page);
    await page.getByTestId('menu-search').fill('audit');
    await page.getByTestId('menu-search').press('Enter');
    await expect(page).toHaveURL(/\/admin\/audit$/);
  });

  test('says so when nothing matches, and does not move [FR-NAV-02]', async ({ page }) => {
    await registerAndEnter(page);
    await openMenu(page);

    await page.getByTestId('menu-search').fill('zzzz');
    await expect(page.getByTestId('menu-search-empty')).toBeVisible();
    await page.getByTestId('menu-search').press('Enter');
    await expect(page).toHaveURL(/\/admin$/);

    await page.getByTestId('menu-search').fill('');
    await expect(page.getByTestId('menu-search-empty')).toHaveCount(0);
    await expect(page.getByTestId('menu-link-audit')).toBeVisible();
  });

  test('makes no request while typing [FR-NAV-02]', async ({ page }) => {
    await registerAndEnter(page);
    await openMenu(page);
    const requests: string[] = [];
    page.on('request', (request) => requests.push(request.url()));

    await page.getByTestId('menu-search').pressSequentially('audit', { delay: 30 });
    await page.waitForTimeout(300);

    expect(requests.filter((url) => !url.startsWith('data:'))).toEqual([]);
  });

  test('is focused by the "/" key unless a field has the focus [FR-NAV-02]', async ({ page }) => {
    test.skip((page.viewportSize()?.width ?? 1280) < 1024, 'the keyboard shortcut is for the desktop menu');
    await registerAndEnter(page);

    await page.locator('body').press('/');
    await expect(page.getByTestId('menu-search')).toBeFocused();
    await expect(page.getByTestId('menu-search')).toHaveValue('');

    // In a field, "/" is a character.
    await page.getByTestId('menu-search').press('Escape');
    await page.getByTestId('menu-search').focus();
    await page.getByTestId('menu-search').press('/');
    await expect(page.getByTestId('menu-search')).toHaveValue('/');
  });
});

test.describe('the drawer on a phone [FR-NAV-03]', () => {
  test.beforeEach(({ page }) => {
    test.skip((page.viewportSize()?.width ?? 1280) >= 1024, 'the menu is fixed from 1024 px');
  });

  test('hides the menu until the menu button is used, and closes in every way [FR-NAV-03]', async ({ page }) => {
    await registerAndEnter(page);

    await expect(page.getByTestId('menu-drawer')).toBeHidden();
    await expect(page.getByTestId('menu-button')).toBeVisible();

    // Escape closes it and puts the focus back on the button.
    await page.getByTestId('menu-button').click();
    await expect(page.getByTestId('menu-drawer')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('menu-drawer')).toBeHidden();
    await expect(page.getByTestId('menu-button')).toBeFocused();

    // A tap outside closes it.
    await page.getByTestId('menu-button').click();
    await page.mouse.click(315, 600);
    await expect(page.getByTestId('menu-drawer')).toBeHidden();

    // Choosing an entry closes it and goes there.
    await page.getByTestId('menu-button').click();
    await page.getByTestId('menu-link-audit').click();
    await expect(page).toHaveURL(/\/admin\/audit$/);
    await expect(page.getByTestId('menu-drawer')).toBeHidden();
  });

  test('moves the focus into the drawer and keeps it there while it is open [NFR-UX-03]', async ({ page }) => {
    await registerAndEnter(page);
    await page.getByTestId('menu-button').click();

    await expect(page.getByTestId('menu-drawer')).toBeVisible();
    for (let i = 0; i < 25; i += 1) {
      const inside = await page.evaluate(() => document.activeElement?.closest('[data-testid="menu-drawer"]') !== null);
      expect(inside, `focus left the drawer after ${i} presses of Tab`).toBe(true);
      await page.keyboard.press('Tab');
    }
    for (let i = 0; i < 25; i += 1) {
      await page.keyboard.press('Shift+Tab');
      const inside = await page.evaluate(() => document.activeElement?.closest('[data-testid="menu-drawer"]') !== null);
      expect(inside, `focus left the drawer going back, press ${i}`).toBe(true);
    }
  });
});

test.describe('the desktop menu [FR-NAV-03]', () => {
  test('is fixed, 272 px wide, with no menu button and no drawer', async ({ page }) => {
    test.skip((page.viewportSize()?.width ?? 1280) < 1024, 'from 1024 px only');
    await registerAndEnter(page);

    await expect(page.getByTestId('menu-button')).toBeHidden();
    const box = await page.getByTestId('side-menu').boundingBox();
    expect(Math.round(box?.width ?? 0)).toBe(272);
    await expect(page.getByTestId('menu-link-audit')).toBeVisible();
  });
});

test.describe('keyboard and structure [NFR-UX-03]', () => {
  test('has a skip link that goes to the content, and a visible focus ring', async ({ page }) => {
    await registerAndEnter(page);
    // A fresh load: after a client-side navigation the browser starts from the last click.
    await page.reload();

    await page.keyboard.press('Tab');
    await expect(page.getByTestId('skip-link')).toBeFocused();
    await expect(page.getByTestId('skip-link')).toBeVisible();
    await page.keyboard.press('Enter');
    const inMain = await page.evaluate(() => {
      const active = document.activeElement;
      const hash = location.hash;
      return Boolean(active?.closest('main')) || Boolean(hash && document.querySelector(hash)?.closest('main'));
    });
    expect(inMain).toBe(true);
  });

  test('has one main landmark, one navigation landmark with a name, and a user menu that opens and closes [NFR-UX-03]', async ({ page }) => {
    await registerAndEnter(page);

    await expect(page.locator('main')).toHaveCount(1);
    await expect(page.locator('nav[aria-label]')).not.toHaveCount(0);

    await page.getByTestId('user-menu').click();
    await expect(page.getByTestId('signout-button')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('signout-button')).toBeHidden();
    await expect(page.getByTestId('user-menu')).toBeFocused();
  });
});

test.describe('who may be here [FR-INST-05]', () => {
  test('sends a visitor who is not signed in to the sign-in page, for every admin page', async ({ page }) => {
    for (const path of ['/admin', '/admin/elections', '/admin/institution', '/admin/audit', '/admin/elections/new']) {
      await page.goto(path);
      await expect(page).toHaveURL(/\/login/);
    }
  });

  test('sends a person who signed out back to the sign-in page, and the back button shows nothing of the admin area', async ({ page }) => {
    await registerAndEnter(page);
    await signOut(page);
    await expect(page).toHaveURL(/\/login$/);

    await page.goBack();
    await expect(page.getByTestId('dashboard')).toHaveCount(0);
    await expect(page).toHaveURL(/\/login/);
  });

  test('lands on the dashboard after sign-in, whichever admin page was asked for [FR-INST-04]', async ({ page }) => {
    const { email } = await registerAndEnter(page);
    await signOut(page);

    await page.goto('/admin/audit');
    await expect(page).toHaveURL(/\/login/);
    await page.getByTestId('login-email').fill(email);
    await page.getByTestId('login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByTestId('dashboard')).toBeVisible();
  });

  test('shows each institution its own name and nobody else\'s [FR-INST-05]', async ({ browser }, testInfo) => {
    const options = testInfo.project.use as Parameters<typeof browser.newContext>[0];
    const first = await (await browser.newContext(options)).newPage();
    const second = await (await browser.newContext(options)).newPage();

    await registerAndEnter(first, { institution: 'Collège Alpha', name: 'Alice Alpha', email: uniqueEmail('alpha') });
    await registerAndEnter(second, { institution: 'Lycée Bêta', name: 'Bruno Bêta', email: uniqueEmail('beta') });

    await expect(first.getByTestId('dashboard')).toContainText('Collège Alpha');
    await expect(first.getByTestId('admin-shell')).not.toContainText('Lycée Bêta');
    await expect(second.getByTestId('dashboard')).toContainText('Lycée Bêta');
    await expect(second.getByTestId('admin-shell')).not.toContainText('Collège Alpha');

    // The API answers each of them only about themselves.
    const me = await second.request.get('/api/v1/auth/me');
    expect((await me.json()).data.institution.name).toBe('Lycée Bêta');

    await first.context().close();
    await second.context().close();
  });
});
