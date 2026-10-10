import { expect, test, type Page } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { createBallot } from '../support/ballots';
import { createCandidate, readCandidates } from '../support/candidates';
import { createElection } from '../support/elections';
import { pngBuffer } from '../support/images';

/*
 * Slice 06d — the candidate photo in the candidate modal, on the rows and in the voter preview.
 * docs/slices/06d-candidate-photo.md. API: docs/api/candidates/PUT-candidates-{candidate}-photo.md and DELETE.
 */

const png = (name = 'photo.png', width = 600, height = 800) => ({
  name,
  mimeType: 'image/png',
  buffer: pngBuffer(width, height),
});

async function setup(page: Page) {
  await registerAndEnter(page);
  const election = await createElection(page, 'Photos');
  const ballot = await createBallot(page, election.id, 'Président(e)');
  await page.goto(`/admin/elections/${election.id}/ballots`);
  return { election, ballot };
}

test.describe('adding a photo', () => {
  test('when registering a candidate: a square preview shows at once, the photo replaces the avatar on the row after saving [FR-CAND-02, FR-CAND-04]', async ({ page }) => {
    const { election, ballot } = await setup(page);

    await page.getByTestId('candidate-add-1').click();
    await page.getByTestId('candidate-form-first-name').fill('Nadège');
    await page.getByTestId('candidate-form-last-name').fill('Pierre-Louis');
    await page.getByTestId('candidate-form-photo-input').setInputFiles(png());
    await expect(page.getByTestId('candidate-form-photo-preview')).toBeVisible();
    await expect(page.getByTestId('candidate-preview-photo')).toBeVisible();
    await page.getByTestId('candidate-form-save').click();

    await expect(page.getByTestId('candidate-modal')).toHaveCount(0);
    const photo = page.getByTestId('candidate-photo-1-1');
    await expect(photo).toBeVisible();
    await expect(photo).toHaveAttribute('src', /^\/media\/[0-9a-f-]{36}-160\.webp$/);
    await expect.poll(() => photo.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    await expect(page.getByTestId('candidate-avatar-1-1')).toHaveCount(0);
    const saved = (await readCandidates(page, election.id))[ballot.id];
    expect(saved[0].photo).toMatchObject({ sm: expect.stringMatching(/-160\.webp$/), md: expect.stringMatching(/-480\.webp$/) });
  });

  test('on an existing candidate, replacing the photo [FR-CAND-02]', async ({ page }) => {
    const { election, ballot } = await setup(page);
    await createCandidate(page, ballot.id, 'Jean', 'Désir');
    await page.reload();

    await page.getByTestId('candidate-edit-1-1').click();
    await page.getByTestId('candidate-form-photo-input').setInputFiles(png('a.png', 400, 400));
    await page.getByTestId('candidate-form-save').click();
    const first = await page.getByTestId('candidate-photo-1-1').getAttribute('src');

    await page.getByTestId('candidate-edit-1-1').click();
    await expect(page.getByTestId('candidate-form-photo-preview')).toBeVisible();
    await page.getByTestId('candidate-form-photo-input').setInputFiles(png('b.png', 500, 500));
    await page.getByTestId('candidate-form-save').click();

    await expect(page.getByTestId('candidate-photo-1-1')).not.toHaveAttribute('src', first!);
    expect((await readCandidates(page, election.id))[ballot.id]).toHaveLength(1);
  });
});

test.describe('removing a photo', () => {
  test('with the remove button: the row goes back to the avatar of the chosen sex [FR-CAND-03]', async ({ page }) => {
    const { ballot } = await setup(page);
    await createCandidate(page, ballot.id, 'Ricardo', 'Saint-Fleur', { sex: 'male' });
    await page.reload();
    await page.getByTestId('candidate-edit-1-1').click();
    await page.getByTestId('candidate-form-photo-input').setInputFiles(png());
    await page.getByTestId('candidate-form-save').click();
    await expect(page.getByTestId('candidate-photo-1-1')).toBeVisible();

    await page.getByTestId('candidate-edit-1-1').click();
    await page.getByTestId('candidate-form-photo-remove').click();
    await expect(page.getByTestId('candidate-form-photo-preview')).toHaveCount(0);
    await page.getByTestId('candidate-form-save').click();

    await expect(page.getByTestId('candidate-photo-1-1')).toHaveCount(0);
    await expect(page.getByTestId('candidate-avatar-1-1')).toHaveAttribute('data-sex', 'male');
  });
});

test.describe('refused files', () => {
  test('a file that is not a picture shows a message beside the photo and keeps the modal open [NFR-SEC-06]', async ({ page }) => {
    const { ballot } = await setup(page);
    await createCandidate(page, ballot.id, 'Stanley', 'Augustin');
    await page.reload();

    await page.getByTestId('candidate-edit-1-1').click();
    await page.getByTestId('candidate-form-photo-input').setInputFiles({
      name: 'photo.png',
      mimeType: 'image/png',
      buffer: Buffer.from('%PDF-1.4 not a picture'),
    });
    await page.getByTestId('candidate-form-save').click();

    await expect(page.getByTestId('candidate-form-error-photo')).toBeVisible();
    await expect(page.getByTestId('candidate-modal')).toBeVisible();
    await expect(page.getByTestId('candidate-photo-1-1')).toHaveCount(0);
  });

  test('a file over 5 MB is refused before it is sent [FR-CAND-04]', async ({ page }) => {
    await setup(page);

    await page.getByTestId('candidate-add-1').click();
    await page.getByTestId('candidate-form-photo-input').setInputFiles({
      name: 'big.png',
      mimeType: 'image/png',
      buffer: Buffer.alloc(5 * 1024 * 1024 + 1, 1),
    });

    await expect(page.getByTestId('candidate-form-error-photo')).toBeVisible();
    await expect(page.getByTestId('candidate-form-photo-preview')).toHaveCount(0);
  });
});
