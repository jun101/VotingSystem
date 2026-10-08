import { expect, test } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { createElection, readElection } from '../support/elections';
import { pngBuffer } from '../support/images';

/*
 * Slice 05 — the election form (screen A04, and the edit of a draft) and the summary page.
 * docs/slices/05-elections.md, parts 2 and 2b. API: docs/api/elections/.
 */

async function fillDates(page: import('@playwright/test').Page, starts: string, ends: string): Promise<void> {
  await page.getByTestId('election-starts').fill(starts);
  await page.getByTestId('election-ends').fill(ends);
}

test.describe('creating', () => {
  test('opens from the "new election" button with the defaults of the institution [FR-ELEC-01]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections');

    await page.getByTestId('election-new-button').click();

    await expect(page).toHaveURL(/\/admin\/elections\/new$/);
    await expect(page.getByTestId('top-bar-title')).toHaveText('Nouvelle élection');
    await expect(page.getByTestId('election-timezone')).toHaveValue('America/Port-au-Prince');
    await expect(page.getByTestId('election-language')).toHaveValue('fr');
    await expect(page.getByTestId('election-order-manual')).toBeChecked();
    await expect(page.getByTestId('election-results-full')).toBeChecked();
    await expect(page.getByTestId('election-next-steps')).toBeVisible();
  });

  test('creates the draft, says the schedule in words while typing, and lands on the summary page [FR-ELEC-01, FR-ELEC-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections/new');

    await page.getByTestId('election-title').fill('Conseil des élèves 2026');
    await page.getByTestId('election-description').fill('Élection annuelle des représentants des élèves.');
    await fillDates(page, '2026-10-12T08:00', '2026-10-16T15:00');

    // The side panel follows what is typed, in the election's time zone.
    await expect(page.getByTestId('election-duration')).toContainText('4 jours');
    await expect(page.getByTestId('election-duration')).toContainText('7 heures');
    await expect(page.getByTestId('election-schedule')).toContainText('12 octobre');
    await expect(page.getByTestId('election-schedule')).toContainText('16 octobre');

    await page.getByTestId('election-order-shuffled').check();
    await page.getByTestId('election-results-winners').check();
    await page.getByTestId('election-save').click();

    await expect(page).toHaveURL(/\/admin\/elections\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('election-summary-title')).toHaveText('Conseil des élèves 2026');
    await expect(page.getByTestId('election-summary-status')).toHaveText('Brouillon');
    await expect(page.getByTestId('election-summary-dates')).toContainText('12 octobre');
    await expect(page.getByTestId('election-summary-timezone')).toContainText('Port-au-Prince');

    // Stored in UTC: 08:00 in Port-au-Prince in October is 12:00 UTC.
    const id = page.url().split('/').pop()!;
    const stored = await readElection(page, id);
    expect(stored.starts_at).toBe('2026-10-12T12:00:00Z');
    expect(stored.ends_at).toBe('2026-10-16T19:00:00Z');
    expect(stored.candidate_order).toBe('shuffled');
    expect(stored.results_display).toBe('winners');
  });

  test('reads the typed times in the chosen time zone [FR-ELEC-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections/new');

    await page.getByTestId('election-title').fill('À Paris');
    await page.getByTestId('election-timezone').selectOption('Europe/Paris');
    await fillDates(page, '2026-10-12T08:00', '2026-10-16T15:00');
    await page.getByTestId('election-save').click();

    await expect(page).toHaveURL(/\/admin\/elections\/[0-9a-f-]{36}$/);
    const stored = await readElection(page, page.url().split('/').pop()!);
    // Paris is UTC+2 in October.
    expect(stored.timezone).toBe('Europe/Paris');
    expect(stored.starts_at).toBe('2026-10-12T06:00:00Z');
    expect(stored.ends_at).toBe('2026-10-16T13:00:00Z');
  });

  test('never crashes on a time that does not exist in the time zone (the spring clock change) [FR-ELEC-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections/new');

    await page.getByTestId('election-title').fill('Changement d\'heure');
    await page.getByTestId('election-timezone').selectOption('America/New_York');
    // 02:30 on 8 March 2026 does not exist in New York.
    await fillDates(page, '2026-03-08T02:30', '2026-03-09T10:00');
    await expect(page.getByTestId('election-schedule')).toBeVisible();
    await page.getByTestId('election-save').click();

    await expect(page).toHaveURL(/\/admin\/elections\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('election-form-error')).toHaveCount(0);
  });

  test('refuses what the rules refuse, under the field, and creates nothing [FR-ELEC-01, FR-ELEC-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections/new');

    // Empty title and no dates.
    await page.getByTestId('election-save').click();
    await expect(page.getByTestId('election-title-error')).toBeVisible();
    await expect(page.getByTestId('election-title')).toBeFocused();

    // The end before the start.
    await page.getByTestId('election-title').fill('Dates à l\'envers');
    await fillDates(page, '2026-10-16T15:00', '2026-10-12T08:00');
    await page.getByTestId('election-save').click();
    await expect(page.getByTestId('election-ends-error')).toBeVisible();
    await expect(page.getByTestId('election-ends-error')).not.toContainText('after_start');
    await expect(page).toHaveURL(/\/admin\/elections\/new$/);

    await page.goto('/admin/elections');
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(0);
  });

  test('"Annuler" goes back to the list without creating anything [FR-ELEC-01]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections/new');
    await page.getByTestId('election-title').fill('Abandonnée');

    await page.getByTestId('election-cancel').click();

    await expect(page).toHaveURL(/\/admin\/elections$/);
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(0);
  });

  test('takes a cover picked in the form, shows it, and refuses a file that is not a picture [FR-ELEC-01, NFR-SEC-06]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections/new');
    await page.getByTestId('election-title').fill('Avec une affiche');
    await fillDates(page, '2026-10-12T08:00', '2026-10-16T15:00');

    await page.getByTestId('election-cover-input').setInputFiles({ name: 'faux.png', mimeType: 'image/png', buffer: Buffer.from('<?php echo 1; ?>') });
    await page.getByTestId('election-save').click();
    await expect(page.getByTestId('election-cover-error')).toBeVisible();
    await expect(page.getByTestId('election-cover-error')).not.toContainText('file_type_not_allowed');

    await page.getByTestId('election-cover-input').setInputFiles({ name: 'affiche.png', mimeType: 'image/png', buffer: pngBuffer(1200, 600) });
    await expect(page.getByTestId('election-cover-preview')).toBeVisible();
    await page.getByTestId('election-save').click();

    await expect(page).toHaveURL(/\/admin\/elections\/[0-9a-f-]{36}$/);
    const cover = page.getByTestId('election-summary-cover');
    await expect(cover).toBeVisible();
    await expect(cover).toHaveAttribute('src', /^\/media\/[0-9a-f-]{36}-(480|960)\.webp$/);
    await expect.poll(() => cover.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
  });
});

test.describe('editing a draft', () => {
  test('shows the stored values in the election\'s time zone, saves a change, and checks the dates together [FR-ELEC-01, FR-ELEC-02]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Avant', '2026-10-12T12:00:00Z', '2026-10-16T19:00:00Z', { description: 'Description d\'avant' });
    await page.goto(`/admin/elections/${election.id}`);

    await page.getByTestId('election-edit').click();

    await expect(page).toHaveURL(new RegExp(`/admin/elections/${election.id}/edit$`));
    await expect(page.getByTestId('election-title')).toHaveValue('Avant');
    await expect(page.getByTestId('election-description')).toHaveValue('Description d\'avant');
    await expect(page.getByTestId('election-starts')).toHaveValue('2026-10-12T08:00');
    await expect(page.getByTestId('election-ends')).toHaveValue('2026-10-16T15:00');

    // The end moved before the start: refused, nothing saved.
    await page.getByTestId('election-ends').fill('2026-10-11T15:00');
    await page.getByTestId('election-save').click();
    await expect(page.getByTestId('election-ends-error')).toBeVisible();
    expect((await readElection(page, election.id)).ends_at).toBe('2026-10-16T19:00:00Z');

    await page.getByTestId('election-title').fill('Après');
    await page.getByTestId('election-ends').fill('2026-10-16T17:00');
    await page.getByTestId('election-save').click();

    await expect(page).toHaveURL(new RegExp(`/admin/elections/${election.id}$`));
    await expect(page.getByTestId('election-summary-title')).toHaveText('Après');
    const stored = await readElection(page, election.id);
    expect(stored.title).toBe('Après');
    expect(stored.ends_at).toBe('2026-10-16T21:00:00Z');
    expect(stored.starts_at).toBe('2026-10-12T12:00:00Z');
  });

  test('removes a cover [FR-ELEC-01]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Avec affiche');
    await page.goto(`/admin/elections/${election.id}/edit`);
    await page.getByTestId('election-cover-input').setInputFiles({ name: 'a.png', mimeType: 'image/png', buffer: pngBuffer(1200, 600) });
    await expect(page.getByTestId('election-cover-preview')).toBeVisible();

    await page.getByTestId('election-cover-remove').click();

    await expect(page.getByTestId('election-cover-preview')).toHaveCount(0);
    await page.getByTestId('election-save').click();
    await expect(page.getByTestId('election-summary-cover')).toHaveCount(0);
    expect((await readElection(page, election.id)).cover).toBeNull();
  });
});

test.describe('the summary page', () => {
  test('shows the facts and the next steps, and duplicates and deletes [FR-ELEC-06, FR-ELEC-03]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Conseil des élèves 2025', '2025-10-13T12:00:00Z', '2025-10-17T19:00:00Z');
    await page.goto(`/admin/elections/${election.id}`);

    await expect(page.getByTestId('election-summary-title')).toHaveText('Conseil des élèves 2025');
    await expect(page.getByTestId('election-summary-status')).toHaveText('Brouillon');
    await expect(page.getByTestId('election-summary-dates')).toContainText('13 octobre');
    await expect(page.getByTestId('election-next-steps')).toBeVisible();
    await expect(page.getByTestId('election-edit')).toBeVisible();

    await page.getByTestId('election-duplicate').click();
    await expect(page).toHaveURL(/\/admin\/elections\/[0-9a-f-]{36}$/);
    await expect(page).not.toHaveURL(new RegExp(election.id));
    await expect(page.getByTestId('election-summary-title')).toHaveText('Copie de Conseil des élèves 2025');

    // Delete the copy: back to the list, with only the first one left.
    await page.getByTestId('election-delete').click();
    await page.getByTestId('election-delete-confirm').click();
    await expect(page).toHaveURL(/\/admin\/elections$/);
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(1);
    await expect(page.getByTestId('election-title-1')).toHaveText('Conseil des élèves 2025');
  });

  test('is the page of "Élections" in the menu [FR-NAV-02]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Menu');
    await page.goto(`/admin/elections/${election.id}`);

    const phone = (page.viewportSize()?.width ?? 1280) < 1024;
    if (phone) await page.getByTestId('menu-button').click();
    await expect(page.getByTestId('menu-link-elections')).toHaveAttribute('aria-current', 'page');
  });
});
