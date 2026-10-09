import { expect, test } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { createElection, createSix, putCover } from '../support/elections';
import { uniqueEmail } from '../support/mail';

/*
 * Slice 05 — the list of elections (screen A03): tiles, year chips, filters, cards, delete, duplicate.
 * docs/slices/05-elections.md, parts 2 and 2b. API: docs/api/elections/.
 */

test.describe('an institution with no election', () => {
  test('sees an invitation in the grid, not an empty page [FR-ELEC-07, FR-NAV-04]', async ({ page }) => {
    await registerAndEnter(page);

    await page.goto('/admin/elections');

    await expect(page.getByTestId('top-bar-title')).toHaveText('Élections');
    await expect(page.getByTestId('election-new-tile')).toBeVisible();
    await expect(page.getByTestId('elections-empty')).toBeVisible();
    await expect(page.getByTestId('tile-all')).toHaveAttribute('data-count', '0');
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(0);
    // Only the "all" tile, and no year chip.
    await expect(page.locator('[data-testid^="tile-"]')).toHaveCount(1);
    await expect(page.locator('[data-testid^="year-"]')).toHaveCount(0);

    await page.getByTestId('election-new-tile').click();
    await expect(page).toHaveURL(/\/admin\/elections\/new$/);
    await expect(page.getByTestId('election-form')).toBeVisible();
  });
});

test.describe('the cards', () => {
  test('show the status, the title, the dates in the election\'s time zone and the figures, newest start first [FR-ELEC-07, FR-ELEC-02]', async ({ page }) => {
    await registerAndEnter(page);
    await createElection(page, 'Ancienne', '2025-10-13T12:00:00Z', '2025-10-17T19:00:00Z');
    await createElection(page, 'Conseil des élèves 2026', '2026-10-12T12:00:00Z', '2026-10-16T19:00:00Z');

    await page.goto('/admin/elections');

    await expect(page.getByTestId('election-title-1')).toHaveText('Conseil des élèves 2026');
    await expect(page.getByTestId('election-title-2')).toHaveText('Ancienne');
    await expect(page.getByTestId('election-status-1')).toHaveText('Brouillon');
    // 12:00 UTC is 08:00 in Port-au-Prince: the same day.
    await expect(page.getByTestId('election-dates-1')).toContainText('12 au 16 oct. 2026');
    await expect(page.getByTestId('election-card-1')).toContainText('Aucun poste');
    await expect(page.getByTestId('election-card-1')).toContainText('aucun électeur');
    await expect(page.getByTestId('election-open-1')).toContainText('Continuer');
    await expect(page.getByTestId('election-duplicate-1')).toBeVisible();
    await expect(page.getByTestId('election-delete-1')).toBeVisible();
  });

  test('show the cover as a thumbnail when there is one [FR-ELEC-01]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Avec une affiche');
    await putCover(page, election.id);

    await page.goto('/admin/elections');

    const cover = page.getByTestId('election-cover-1');
    await expect(cover).toBeVisible();
    await expect(cover).toHaveAttribute('src', /^\/media\/[0-9a-f-]{36}-480\.webp$/);
    await expect.poll(() => cover.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
  });

  test('open the summary page of the election [FR-ELEC-01]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'À ouvrir');
    await page.goto('/admin/elections');

    await page.getByTestId('election-open-1').click();

    await expect(page).toHaveURL(new RegExp(`/admin/elections/${election.id}$`));
    await expect(page.getByTestId('election-page')).toBeVisible();
    await expect(page.getByTestId('election-summary-title')).toHaveText('À ouvrir');
  });
});

test.describe('the tiles and the year chips', () => {
  test('count by status and by year, and filter the grid; the filter lives in the address [FR-ELEC-07]', async ({ page }) => {
    await registerAndEnter(page);
    await createSix(page);
    await page.goto('/admin/elections');

    await expect(page.getByTestId('tile-all')).toHaveAttribute('data-count', '6');
    await expect(page.getByTestId('tile-draft')).toHaveAttribute('data-count', '6');
    // No tile for a status with no election.
    await expect(page.getByTestId('tile-open')).toHaveCount(0);
    await expect(page.getByTestId('tile-archived')).toHaveCount(0);
    await expect(page.getByTestId('year-2026')).toBeVisible();
    await expect(page.getByTestId('year-2025')).toBeVisible();
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(6);

    // The page keeps living while the filter changes: a marker on the window survives.
    await page.evaluate(() => {
      (window as unknown as { __kept: number }).__kept = 1;
    });

    await page.getByTestId('year-2025').click();
    await expect(page).toHaveURL(/year=2025/);
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(3);
    await expect(page.getByTestId('year-2025')).toHaveAttribute('data-active', 'true');
    await expect(page.getByTestId('tile-all')).toHaveAttribute('data-count', '6');   // the counts ignore the filters
    expect(await page.evaluate(() => (window as unknown as { __kept?: number }).__kept)).toBe(1);

    await page.getByTestId('tile-draft').click();
    await expect(page).toHaveURL(/status=draft/);
    await expect(page).toHaveURL(/year=2025/);
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(3);

    // A reload keeps the filters.
    await page.reload();
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(3);
    await expect(page.getByTestId('tile-draft')).toHaveAttribute('data-active', 'true');
    await expect(page.getByTestId('year-2025')).toHaveAttribute('data-active', 'true');

    // "Toutes" clears the status, the year chip again clears the year.
    await page.getByTestId('tile-all').click();
    await expect(page).not.toHaveURL(/status=/);
    await page.getByTestId('year-2025').click();
    await expect(page).not.toHaveURL(/year=/);
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(6);

    // The back button goes back through the filters.
    await page.goBack();
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(3);
  });
});

test.describe('deleting and duplicating', () => {
  test('a draft is deleted after a confirmation that names it; the counts follow [FR-ELEC-03]', async ({ page }) => {
    await registerAndEnter(page);
    await createElection(page, 'À supprimer', '2026-10-12T12:00:00Z', '2026-10-16T19:00:00Z');
    await createElection(page, 'À garder', '2025-10-12T12:00:00Z', '2025-10-16T19:00:00Z');
    await page.goto('/admin/elections');
    await expect(page.getByTestId('tile-all')).toHaveAttribute('data-count', '2');

    await page.getByTestId('election-delete-1').click();
    await expect(page.getByTestId('election-delete-dialog')).toContainText('À supprimer');
    await page.getByTestId('election-delete-cancel').click();
    await expect(page.getByTestId('election-delete-dialog')).toHaveCount(0);
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(2);

    await page.getByTestId('election-delete-1').click();
    await page.getByTestId('election-delete-confirm').click();

    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(1);
    await expect(page.getByTestId('election-title-1')).toHaveText('À garder');
    await expect(page.getByTestId('tile-all')).toHaveAttribute('data-count', '1');
    await expect(page.getByTestId('year-2026')).toHaveCount(0);
    await page.reload();
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(1);
  });

  test('a duplicate appears as a new draft "Copie de …", without leaving the list [FR-ELEC-06]', async ({ page }) => {
    await registerAndEnter(page);
    await createElection(page, 'Conseil des élèves 2025', '2025-10-13T12:00:00Z', '2025-10-17T19:00:00Z', { description: 'Annuelle' });
    await page.goto('/admin/elections');

    await page.getByTestId('election-duplicate-1').click();

    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(2);
    await expect(page.locator('[data-testid^="election-title-"]', { hasText: 'Copie de Conseil des élèves 2025' })).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/elections$/);
    await expect(page.getByTestId('tile-all')).toHaveAttribute('data-count', '2');
  });
});

test.describe('two institutions', () => {
  test('each sees only its own elections [FR-INST-05]', async ({ browser }) => {
    const a = await (await browser.newContext({ locale: 'fr-FR' })).newPage();
    const b = await (await browser.newContext({ locale: 'fr-FR' })).newPage();
    await registerAndEnter(a, { email: uniqueEmail('a'), institution: 'Institution A' });
    await registerAndEnter(b, { email: uniqueEmail('b'), institution: 'Institution B' });
    const secret = await createElection(a, 'Élection secrète de A');
    await createElection(b, 'Élection de B');

    await b.goto('/admin/elections');
    await expect(b.locator('[data-testid^="election-card-"]')).toHaveCount(1);
    expect(await b.getByTestId('elections-page').innerText()).not.toContain('secrète');

    // Another institution's address is a plain "not found", like an unknown one.
    const response = await b.goto(`/admin/elections/${secret.id}`);
    expect(response?.status()).toBe(404);
    await expect(b.getByTestId('election-page')).toHaveCount(0);

    await a.context().close();
    await b.context().close();
  });
});

test.describe('in English', () => {
  test.use({ locale: 'en-US' });

  test('the words follow the language of the person [NFR-UX-01]', async ({ page }) => {
    await registerAndEnter(page);
    await createElection(page, 'Student council 2026');

    await page.goto('/admin/elections');

    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByTestId('top-bar-title')).toHaveText('Elections');
    await expect(page.getByTestId('tile-all')).toContainText('All');
    await expect(page.getByTestId('election-status-1')).toHaveText('Draft');
    await expect(page.getByTestId('election-new-button')).toContainText('New election');
    await expect(page.getByTestId('election-dates-1')).toContainText('Oct');
  });
});
