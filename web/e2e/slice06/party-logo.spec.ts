import { expect, test } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { createElection } from '../support/elections';
import { pngBuffer } from '../support/images';
import { createParty, readParties } from '../support/parties';

/*
 * Slice 06b2 — the party logo in the party modal and on the parties card.
 * docs/slices/06b2-party-logo.md. API: docs/api/parties/PUT-parties-{party}-logo.md and DELETE-parties-{party}-logo.md.
 */

const png = (name = 'logo.png', width = 400, height = 400) => ({
  name,
  mimeType: 'image/png',
  buffer: pngBuffer(width, height),
});

async function open(page: import('@playwright/test').Page) {
  await registerAndEnter(page);
  const election = await createElection(page, 'Logos');
  await page.goto(`/admin/elections/${election.id}/ballots`);
  return election;
}

test.describe('adding a logo', () => {
  test('when registering a party: a preview shows at once and the logo is on the card after saving [FR-CAND-01, FR-CAND-04]', async ({ page }) => {
    const election = await open(page);

    await page.getByTestId('party-new').click();
    await page.getByTestId('party-form-name').fill('Avenir Étudiant');
    await page.getByTestId('party-form-logo-input').setInputFiles(png());
    await expect(page.getByTestId('party-form-logo-preview')).toBeVisible();
    await page.getByTestId('party-form-save').click();

    await expect(page.getByTestId('party-modal')).toHaveCount(0);
    const logo = page.getByTestId('party-logo-1');
    await expect(logo).toBeVisible();
    await expect(logo).toHaveAttribute('src', /^\/media\/[0-9a-f-]{36}-96\.webp$/);
    await expect.poll(() => logo.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    const [party] = await readParties(page, election.id);
    expect(party).toMatchObject({ name: 'Avenir Étudiant' });
  });

  test('on an existing party, by the file field or by dropping, replacing the logo [FR-CAND-01]', async ({ page }) => {
    const election = await open(page);
    await createParty(page, election.id, 'Ensemble');
    await page.reload();

    await page.getByTestId('party-edit-1').click();
    await page.getByTestId('party-form-logo-input').setInputFiles(png('a.png', 300, 300));
    await page.getByTestId('party-form-save').click();
    const first = await page.getByTestId('party-logo-1').getAttribute('src');

    await page.getByTestId('party-edit-1').click();
    await expect(page.getByTestId('party-form-logo-preview')).toBeVisible();
    await page.getByTestId('party-form-logo-input').setInputFiles(png('b.png', 500, 500));
    await page.getByTestId('party-form-save').click();

    await expect(page.getByTestId('party-logo-1')).not.toHaveAttribute('src', first!);
  });
});

test.describe('removing a logo', () => {
  test('with the remove button of the modal [FR-CAND-01]', async ({ page }) => {
    const election = await open(page);
    await createParty(page, election.id, 'Alpha', '#5468D4', 'AL');
    await page.reload();
    await page.getByTestId('party-edit-1').click();
    await page.getByTestId('party-form-logo-input').setInputFiles(png());
    await page.getByTestId('party-form-save').click();
    await expect(page.getByTestId('party-logo-1')).toBeVisible();

    await page.getByTestId('party-edit-1').click();
    await page.getByTestId('party-form-logo-remove').click();
    await expect(page.getByTestId('party-form-logo-preview')).toHaveCount(0);
    await page.getByTestId('party-form-save').click();

    await expect(page.getByTestId('party-logo-1')).toHaveCount(0);
    await expect(page.getByTestId('party-swatch-1')).toContainText('AL');
    expect((await page.request.get(`/api/v1/elections/${election.id}/parties`)).ok()).toBe(true);
  });
});

test.describe('refused files', () => {
  test('a file that is not a picture shows a message beside the logo and keeps the modal open [NFR-SEC-06]', async ({ page }) => {
    const election = await open(page);
    await createParty(page, election.id, 'Beta');
    await page.reload();

    await page.getByTestId('party-edit-1').click();
    await page.getByTestId('party-form-logo-input').setInputFiles({
      name: 'logo.png',
      mimeType: 'image/png',
      buffer: Buffer.from('%PDF-1.4 not a picture'),
    });
    await page.getByTestId('party-form-save').click();

    await expect(page.getByTestId('party-form-error-logo')).toBeVisible();
    await expect(page.getByTestId('party-modal')).toBeVisible();
    await expect(page.getByTestId('party-logo-1')).toHaveCount(0);
  });

  test('a file over 5 MB is refused before it is sent, with a message [FR-CAND-04]', async ({ page }) => {
    await open(page);

    await page.getByTestId('party-new').click();
    await page.getByTestId('party-form-name').fill('Gros fichier');
    await page.getByTestId('party-form-logo-input').setInputFiles({
      name: 'big.png',
      mimeType: 'image/png',
      buffer: Buffer.alloc(5 * 1024 * 1024 + 1, 1),
    });

    await expect(page.getByTestId('party-form-error-logo')).toBeVisible();
    await expect(page.getByTestId('party-form-logo-preview')).toHaveCount(0);
  });
});
