import { expect, test, type Page } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { createElection } from '../support/elections';
import { csvFile, importIdOf, readImport, templateFile } from '../support/imports';
import { cardNames, readGroups, readVoters } from '../support/voters';

/*
 * Slice 08 — importing voters from a file: A09 (template and file) and A10 (check and import).
 * docs/slices/08-import.md. API: docs/api/voter-imports/. Mockup: material-voter-import.html.
 */

async function openImport(page: Page, title = 'Import') {
  await registerAndEnter(page);
  const election = await createElection(page, title);
  await page.goto(`/admin/elections/${election.id}/voters/import`);
  return election;
}

/** Chooses a file and sends it; the page goes to the check step. */
async function upload(page: Page, file: { name: string; mimeType: string; buffer: Buffer }) {
  await page.getByTestId('import-file-input').setInputFiles(file);
  await expect(page.getByTestId('import-file-name')).toHaveText(file.name);
  await page.getByTestId('import-upload').click();
}

const MIXED = templateFile([
  ['Rose-Marie Désir', '4e année', 'E-2041', 'rm.desir@example.ht', '+509 3712 4455'],
  ['Jean-Baptiste Pierre', '4e année', 'E-2042', '', ''],
  ['', '5e année', 'E-2107', '', ''],
  ['Nadège Louis', '5e année', 'E-2108', 'nadege.louis@', ''],
  ['Wideline Joseph', '4e année', 'E-2041', '', ''],
]);

test.describe('the template and the file (A09)', () => {
  test('shows the steps, the template, the instructions and the drop zone [FR-VOT-03]', async ({ page }) => {
    await openImport(page);

    await expect(page.getByTestId('import-page')).toBeVisible();
    await expect(page.getByTestId('import-step-1')).toHaveAttribute('aria-current', 'step');
    await expect(page.getByTestId('import-template-preview')).toContainText('nom_complet');
    await expect(page.getByTestId('import-instructions')).toBeVisible();
    await expect(page.getByTestId('import-dropzone')).toBeVisible();
    await expect(page.getByTestId('import-upload')).toBeDisabled();
  });

  test('downloads the template as an Excel file and as a CSV [FR-VOT-03]', async ({ page }) => {
    await openImport(page);

    const [xlsx] = await Promise.all([page.waitForEvent('download'), page.getByTestId('import-template-xlsx').click()]);
    expect(xlsx.suggestedFilename()).toBe('modele-electeurs.xlsx');
    const [csv] = await Promise.all([page.waitForEvent('download'), page.getByTestId('import-template-csv').click()]);
    expect(csv.suggestedFilename()).toBe('modele-electeurs.csv');
  });

  test('is reached from the voters page and goes back to it [FR-VOT-03]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Chemin');
    await page.goto(`/admin/elections/${election.id}/voters`);

    await page.getByTestId('voters-import').click();
    await expect(page).toHaveURL(`/admin/elections/${election.id}/voters/import`);
    await page.getByTestId('import-back').click();

    await expect(page).toHaveURL(`/admin/elections/${election.id}/voters`);
  });

  test('refuses a file that is not a spreadsheet or a text file and stays on the page [NFR-SEC-06]', async ({ page }) => {
    const election = await openImport(page);
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

    await upload(page, { name: 'liste.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: png });

    await expect(page.getByTestId('import-error')).toBeVisible();
    await expect(page).toHaveURL(`/admin/elections/${election.id}/voters/import`);
  });

  test('goes to the check step after the upload [FR-VOT-03]', async ({ page }) => {
    const election = await openImport(page);

    await upload(page, MIXED);

    await expect(page).toHaveURL(new RegExp(`/admin/elections/${election.id}/voters/import/[0-9a-f-]{36}$`));
    await expect(page.getByTestId('check-page')).toBeVisible();
    await expect(page.getByTestId('import-step-3')).toHaveAttribute('aria-current', 'step');
  });
});

test.describe('the check (A10)', () => {
  test('shows the counts, the new groups and every row with its status, nothing imported yet [FR-VOT-03]', async ({ page }) => {
    const election = await openImport(page);

    await upload(page, MIXED);

    await expect(page.getByTestId('check-total')).toContainText('5');
    await expect(page.getByTestId('check-valid')).toContainText('2');
    await expect(page.getByTestId('check-invalid')).toContainText('3');
    await expect(page.getByTestId('check-duplicates')).toContainText('1');
    await expect(page.getByTestId('check-new-groups')).toContainText('4e année');
    await expect(page.locator('[data-testid^="check-row-status-"]')).toHaveCount(5);
    await expect(page.getByTestId('check-row-3')).toContainText('E-2107');
    await expect(page.getByTestId('check-confirm')).toContainText('2');
    expect(await readVoters(page, election.id)).toHaveLength(0);
    expect(await readGroups(page, election.id)).toHaveLength(0);
  });

  test('shows only the errors when asked [FR-VOT-03]', async ({ page }) => {
    await openImport(page);
    await upload(page, MIXED);
    await expect(page.locator('[data-testid^="check-row-status-"]')).toHaveCount(5);

    await page.getByTestId('check-filter-errors').click();

    await expect(page.locator('[data-testid^="check-row-status-"]')).toHaveCount(3);
  });

  test('corrects the column mapping and the preview follows [FR-VOT-03]', async ({ page }) => {
    await openImport(page);
    await upload(page, csvFile([
      ['Nom', 'Classe', 'Contact'],
      ['Rose-Marie Désir', '4e année', 'rose@example.ht'],
      ['Jean-Baptiste Pierre', '5e année', 'pas-un-courriel'],
    ]));
    await expect(page.getByTestId('check-valid')).toContainText('2');
    await expect(page.getByTestId('map-select-full_name')).toHaveValue('0');
    await expect(page.getByTestId('map-select-group')).toHaveValue('1');

    await page.getByTestId('map-select-email').selectOption('2');

    await expect(page.getByTestId('check-valid')).toContainText('1');
    await expect(page.getByTestId('check-invalid')).toContainText('1');
  });

  test('downloads the rejected rows as a file [FR-VOT-03]', async ({ page }) => {
    await openImport(page);
    await upload(page, MIXED);

    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('check-error-file').click()]);

    expect(download.suggestedFilename()).toBe('erreurs-electeurs.xlsx');
  });

  test('says why a file cannot be used [FR-VOT-04]', async ({ page }) => {
    await openImport(page);

    await upload(page, csvFile([['groupe', 'courriel'], ['4e année', 'a@example.ht']]));

    await expect(page.getByTestId('check-failed')).toBeVisible();
    await page.getByTestId('check-retry').click();
    await expect(page.getByTestId('import-page')).toBeVisible();
  });

  test('cancels: nothing is imported and the import is gone [FR-VOT-03]', async ({ page }) => {
    const election = await openImport(page);
    await upload(page, MIXED);
    await expect(page).toHaveURL(/\/voters\/import\/[0-9a-f-]{36}$/);
    const id = importIdOf(page.url());

    await page.getByTestId('check-cancel').click();

    await expect(page).toHaveURL(`/admin/elections/${election.id}/voters`);
    expect((await page.request.get(`/api/v1/voter-imports/${id}`)).status()).toBe(404);
    expect(await readVoters(page, election.id)).toHaveLength(0);
  });
});

test.describe('the import (A10, steps 3 to 4)', () => {
  test('imports the valid rows in the background and shows the result [FR-VOT-03, FR-VOT-04]', async ({ page }) => {
    const election = await openImport(page);
    await upload(page, MIXED);
    await expect(page).toHaveURL(/\/voters\/import\/[0-9a-f-]{36}$/);
    const id = importIdOf(page.url());

    await page.getByTestId('check-confirm').click();

    await expect(page.getByTestId('import-done')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('import-step-4')).toHaveAttribute('aria-current', 'step');
    await expect(page.getByTestId('import-done-count')).toContainText('2');
    await expect(page.getByTestId('import-done-errors')).toContainText('3');
    const done = await readImport(page, id);
    expect(done).toMatchObject({ status: 'done', imported_rows: 2 });
    const voters = await readVoters(page, election.id);
    expect(voters.map((voter) => voter.full_name).sort()).toEqual(['Jean-Baptiste Pierre', 'Rose-Marie Désir']);
    expect((await readGroups(page, election.id)).map((group) => group.name)).toEqual(['4e année']);

    await page.getByTestId('import-done-voters').click();
    await expect(page).toHaveURL(`/admin/elections/${election.id}/voters`);
    expect(await cardNames(page)).toEqual(['Jean-Baptiste Pierre', 'Rose-Marie Désir']);
  });

  test('shows a progress bar while a larger file is imported [FR-VOT-04]', async ({ page }) => {
    await openImport(page);
    const rows = Array.from({ length: 600 }, (_, i) => [`Électeur ${i + 1}`, `Groupe ${i % 4}`, `ID-${i + 1}`, `e${i + 1}@example.ht`, '']);
    await upload(page, templateFile(rows, 'grand.csv'));
    await expect(page.getByTestId('check-valid')).toContainText('600');

    await page.getByTestId('check-confirm').click();

    await expect(page.getByTestId('import-done')).toBeVisible({ timeout: 60_000 });
    await expect(page.getByTestId('import-done-count')).toContainText('600');
  });
});

test.describe('quality', () => {
  test('is accessible and never scrolls sideways on both screens [NFR-UX-02, NFR-UX-03]', async ({ page }) => {
    await openImport(page);

    await expectAccessible(page);
    await expectNoSidewaysScroll(page);

    await upload(page, MIXED);
    await expect(page.getByTestId('check-rows')).toBeVisible();
    await expectAccessible(page);
    await expectNoSidewaysScroll(page);
  });
});
