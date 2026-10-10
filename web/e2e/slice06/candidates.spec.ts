import { expect, test, type Page } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { createBallot } from '../support/ballots';
import { candidateNames, createCandidate, readCandidates } from '../support/candidates';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { createElection } from '../support/elections';
import { createParty } from '../support/parties';

/*
 * Slice 06c — candidates on the ballots page: the rows in the ballot cards, the candidate modal (screen A07),
 * reordering, moving, the warnings and the counts. docs/slices/06c-candidates.md. API: docs/api/candidates/.
 * Design: docs/design/frontend.md 1.5 items 9 to 12, mockups material-ballots.html and material-candidate-modal.html.
 */

async function setup(page: Page) {
  await registerAndEnter(page);
  const election = await createElection(page, 'Candidats');
  const president = await createBallot(page, election.id, 'Président(e)');
  const secretary = await createBallot(page, election.id, 'Secrétaire');
  return { election, president, secretary };
}

async function openPage(page: Page, election: string) {
  await page.goto(`/admin/elections/${election}/ballots`);
}

test.describe('a ballot with no candidate', () => {
  test('keeps its invitation and offers the add button [FR-CAND-02]', async ({ page }) => {
    const { election } = await setup(page);
    await openPage(page, election.id);

    await expect(page.getByTestId('ballot-card-1')).toContainText('Aucun candidat');
    await expect(page.getByTestId('candidate-add-1')).toBeVisible();
    await expect(page.locator('[data-testid^="candidate-row-1-"]')).toHaveCount(0);
  });
});

test.describe('registering a candidate in a modal', () => {
  test('opens from the card with the ballot already chosen, saves and closes without leaving the page [FR-CAND-02, FR-CAND-03]', async ({ page }) => {
    const { election, president } = await setup(page);
    const party = await createParty(page, election.id, 'Avenir Étudiant', '#5468D4', 'AE');
    await openPage(page, election.id);
    const url = page.url();

    await page.getByTestId('candidate-add-1').click();
    await expect(page.getByTestId('candidate-modal')).toBeVisible();
    await expect(page.getByTestId('candidate-modal')).toHaveAttribute('role', 'dialog');
    await expect(page.getByTestId('candidate-form-ballot')).toHaveValue(president.id);
    await page.getByTestId('candidate-form-first-name').fill('Nadège');
    await page.getByTestId('candidate-form-last-name').fill('Pierre-Louis');
    await page.getByTestId('candidate-form-sex-female').check();
    await page.getByTestId('candidate-form-party').selectOption(party.id);
    await page.getByTestId('candidate-form-slogan').fill('Une école qui nous écoute');
    await page.getByTestId('candidate-form-save').click();

    await expect(page.getByTestId('candidate-modal')).toHaveCount(0);
    expect(page.url()).toBe(url);
    await expect(page.getByTestId('candidate-name-1-1')).toHaveText('Nadège Pierre-Louis');
    await expect(page.getByTestId('candidate-party-1-1')).toContainText('Avenir Étudiant');
    await expect(page.getByTestId('candidate-avatar-1-1')).toHaveAttribute('data-sex', 'female');
    await expect(page.getByTestId('party-count-1')).toContainText('1');
    const saved = (await readCandidates(page, election.id))[president.id];
    expect(saved).toMatchObject([{ first_name: 'Nadège', last_name: 'Pierre-Louis', sex: 'female', party: party.id, slogan: 'Une école qui nous écoute' }]);
  });

  test('shows a live preview of the voter card and the avatar of the chosen sex [FR-CAND-03]', async ({ page }) => {
    const { election } = await setup(page);
    await createParty(page, election.id, 'Ensemble', '#C2410C');
    await openPage(page, election.id);

    await page.getByTestId('candidate-add-1').click();
    await page.getByTestId('candidate-form-first-name').fill('Jean-Marc');
    await page.getByTestId('candidate-form-last-name').fill('Désir');
    await page.getByTestId('candidate-form-sex-male').check();
    await page.getByTestId('candidate-form-slogan').fill('Ensemble, plus loin');

    await expect(page.getByTestId('candidate-preview')).toContainText('Jean-Marc Désir');
    await expect(page.getByTestId('candidate-preview')).toContainText('Ensemble, plus loin');
    await expect(page.getByTestId('candidate-preview-avatar')).toHaveAttribute('data-sex', 'male');
    await page.getByTestId('candidate-form-sex-female').check();
    await expect(page.getByTestId('candidate-preview-avatar')).toHaveAttribute('data-sex', 'female');
    await expect(page.getByTestId('candidate-form-slogan-count')).toContainText('19');
  });

  test('"save and add another" keeps the modal open with the ballot kept and the focus on the first name [FR-CAND-02]', async ({ page }) => {
    const { election, president } = await setup(page);
    await openPage(page, election.id);

    await page.getByTestId('candidate-add-1').click();
    await page.getByTestId('candidate-form-first-name').fill('Premier');
    await page.getByTestId('candidate-form-last-name').fill('Candidat');
    await page.getByTestId('candidate-form-save-another').click();

    await expect(page.getByTestId('candidate-modal')).toBeVisible();
    await expect(page.getByTestId('candidate-form-first-name')).toHaveValue('');
    await expect(page.getByTestId('candidate-form-first-name')).toBeFocused();
    await expect(page.getByTestId('candidate-form-ballot')).toHaveValue(president.id);
    await page.getByTestId('candidate-form-first-name').fill('Deuxième');
    await page.getByTestId('candidate-form-last-name').fill('Candidat');
    await page.getByTestId('candidate-form-save').click();

    await expect.poll(() => candidateNames(page, 1)).toEqual(['Premier Candidat', 'Deuxième Candidat']);
  });

  test('refuses empty names beside the fields and stays open [FR-CAND-02]', async ({ page }) => {
    const { election, president } = await setup(page);
    await openPage(page, election.id);

    await page.getByTestId('candidate-add-1').click();
    await page.getByTestId('candidate-form-save').click();

    await expect(page.getByTestId('candidate-form-error-first-name')).toBeVisible();
    await expect(page.getByTestId('candidate-form-error-last-name')).toBeVisible();
    await expect(page.getByTestId('candidate-modal')).toBeVisible();
    expect((await readCandidates(page, election.id))[president.id]).toEqual([]);
  });

  test('closes with Escape and returns the focus to the button that opened it [FR-CAND-02]', async ({ page }) => {
    const { election } = await setup(page);
    await openPage(page, election.id);

    await page.getByTestId('candidate-add-1').click();
    await page.keyboard.press('Escape');

    await expect(page.getByTestId('candidate-modal')).toHaveCount(0);
    await expect(page.getByTestId('candidate-add-1')).toBeFocused();
  });
});

test.describe('editing, moving and deleting', () => {
  test('edits a candidate in the same modal, filled with its values, and moves it to another ballot [FR-CAND-02]', async ({ page }) => {
    const { election, president, secretary } = await setup(page);
    await createCandidate(page, president.id, 'Avant', 'Nom', { slogan: 'Mot' });
    await openPage(page, election.id);

    await page.getByTestId('candidate-edit-1-1').click();
    await expect(page.getByTestId('candidate-form-first-name')).toHaveValue('Avant');
    await expect(page.getByTestId('candidate-form-slogan')).toHaveValue('Mot');
    await page.getByTestId('candidate-form-first-name').fill('Après');
    await page.getByTestId('candidate-form-ballot').selectOption(secretary.id);
    await page.getByTestId('candidate-form-save').click();

    await expect(page.locator('[data-testid^="candidate-row-1-"]')).toHaveCount(0);
    await expect(page.getByTestId('candidate-name-2-1')).toHaveText('Après Nom');
    const all = await readCandidates(page, election.id);
    expect(all[president.id]).toEqual([]);
    expect(all[secretary.id]).toMatchObject([{ first_name: 'Après' }]);
  });

  test('deletes after a confirmation that names the candidate, and the positions close up [FR-CAND-02]', async ({ page }) => {
    const { election, president } = await setup(page);
    await createCandidate(page, president.id, 'Un', 'A');
    await createCandidate(page, president.id, 'Deux', 'B');
    await createCandidate(page, president.id, 'Trois', 'C');
    await openPage(page, election.id);

    await page.getByTestId('candidate-delete-1-2').click();
    await expect(page.getByTestId('candidate-delete-dialog')).toContainText('Deux B');
    await page.getByTestId('candidate-delete-cancel').click();
    expect(await candidateNames(page, 1)).toEqual(['Un A', 'Deux B', 'Trois C']);

    await page.getByTestId('candidate-delete-1-2').click();
    await page.getByTestId('candidate-delete-confirm').click();

    await expect.poll(() => candidateNames(page, 1)).toEqual(['Un A', 'Trois C']);
    expect((await readCandidates(page, election.id))[president.id].map((c) => c.position)).toEqual([1, 2]);
  });

  test('a candidate whose party is deleted stays and becomes independent [FR-CAND-01]', async ({ page }) => {
    const { election, president } = await setup(page);
    const party = await createParty(page, election.id, 'Ensemble');
    await createCandidate(page, president.id, 'Jean', 'Désir', { party: party.id });
    await openPage(page, election.id);
    await expect(page.getByTestId('candidate-party-1-1')).toContainText('Ensemble');

    await page.getByTestId('party-delete-1').click();
    await page.getByTestId('party-delete-confirm').click();

    await expect(page.getByTestId('candidate-name-1-1')).toHaveText('Jean Désir');
    await expect(page.getByTestId('candidate-party-1-1')).toContainText('Indépendant');
  });
});

test.describe('reordering', () => {
  test('with the arrows, saved at once and kept after a reload [FR-CAND-02]', async ({ page }) => {
    const { election, president } = await setup(page);
    await createCandidate(page, president.id, 'A', 'A');
    await createCandidate(page, president.id, 'B', 'B');
    await createCandidate(page, president.id, 'C', 'C');
    await openPage(page, election.id);

    await expect(page.getByTestId('candidate-up-1-1')).toBeDisabled();
    await expect(page.getByTestId('candidate-down-1-3')).toBeDisabled();
    await page.getByTestId('candidate-down-1-1').click();
    await expect.poll(() => candidateNames(page, 1)).toEqual(['B B', 'A A', 'C C']);
    await page.getByTestId('candidate-up-1-3').click();
    await expect.poll(() => candidateNames(page, 1)).toEqual(['B B', 'C C', 'A A']);

    await page.reload();
    expect(await candidateNames(page, 1)).toEqual(['B B', 'C C', 'A A']);
    expect((await readCandidates(page, election.id))[president.id].map((c) => c.first_name)).toEqual(['B', 'C', 'A']);
  });

  test('by dragging the grip of a row onto another [FR-CAND-02]', async ({ page }) => {
    test.skip((page.viewportSize()?.width ?? 1280) < 1024, 'drag and drop is a pointer path; phones use the arrows');
    const { election, president } = await setup(page);
    await createCandidate(page, president.id, 'A', 'A');
    await createCandidate(page, president.id, 'B', 'B');
    await createCandidate(page, president.id, 'C', 'C');
    await openPage(page, election.id);

    await page.getByTestId('candidate-grip-1-3').dragTo(page.getByTestId('candidate-row-1-1'));

    await expect.poll(() => candidateNames(page, 1)).toEqual(['C C', 'A A', 'B B']);
    await page.reload();
    expect(await candidateNames(page, 1)).toEqual(['C C', 'A A', 'B B']);
  });
});

test.describe('warnings and counts', () => {
  test('a ballot with one candidate shows a notice, and the "to check" card lists the ballots to complete [FR-ELEC-04]', async ({ page }) => {
    const { election, president } = await setup(page);
    await createCandidate(page, president.id, 'Seul', 'Candidat');
    await openPage(page, election.id);

    await expect(page.getByTestId('ballot-warning-1')).toBeVisible();
    await expect(page.getByTestId('ballots-checks')).toContainText('Président(e)');
    await expect(page.getByTestId('ballots-checks')).toContainText('Secrétaire');
    await expect(page.getByTestId('ballot-warning-2')).toHaveCount(0);

    await createCandidate(page, president.id, 'Deuxième', 'Candidat');
    await page.reload();
    await expect(page.getByTestId('ballot-warning-1')).toHaveCount(0);
  });

  test('the ballot tag and the election card show the candidate counts [FR-CAND-02]', async ({ page }) => {
    const { election, president } = await setup(page);
    await createCandidate(page, president.id, 'A', 'A');
    await createCandidate(page, president.id, 'B', 'B');
    await openPage(page, election.id);

    await expect(page.getByTestId('ballot-candidates-1')).toContainText('2');
    await expect(page.getByTestId('candidates-count')).toContainText('2');
  });
});

test.describe('duplicating an election with its candidates', () => {
  test('the duplicate option copies the candidates with their parties [FR-ELEC-06]', async ({ page }) => {
    const { election, president } = await setup(page);
    const party = await createParty(page, election.id, 'Ensemble', '#C2410C');
    await createCandidate(page, president.id, 'Jean', 'Désir', { party: party.id });

    const response = await page.request.post(`/api/v1/elections/${election.id}/duplicate`, {
      headers: {
        'X-XSRF-TOKEN': decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? ''),
        Accept: 'application/json',
      },
      data: { copy_candidates: true },
    });
    expect(response.status()).toBe(201);
    const copy = ((await response.json()) as { data: { id: string } }).data.id;

    const candidates = Object.values(await readCandidates(page, copy)).flat();
    expect(candidates).toMatchObject([{ first_name: 'Jean', last_name: 'Désir' }]);
    expect(candidates[0].party).not.toBeNull();
    expect(candidates[0].party).not.toBe(party.id);
  });
});

test.describe('on every screen (frontend.md 1.5 items 10 to 12)', () => {
  test('1920 by 1080: the modal fits the screen, is accessible, and the rows are readable [NFR-UX-03]', async ({ page }) => {
    const { election, president } = await setup(page);
    await createCandidate(page, president.id, 'Nadège', 'Pierre-Louis');
    await page.setViewportSize({ width: 1920, height: 1080 });
    await openPage(page, election.id);

    await page.getByTestId('candidate-add-1').click();

    const box = (await page.getByTestId('candidate-modal').boundingBox())!;
    expect(box.width).toBeLessThanOrEqual(1130);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(box.y + box.height).toBeLessThanOrEqual(1080);
    await expectAccessible(page);
  });

  test('phone: the modal is a bottom sheet, no sideways scroll, accessible [NFR-UX-02, NFR-UX-03]', async ({ page }) => {
    const { election, president } = await setup(page);
    await createCandidate(page, president.id, 'Nadège', 'Pierre-Louis');
    await page.setViewportSize({ width: 360, height: 780 });
    await openPage(page, election.id);
    await expectNoSidewaysScroll(page);

    await page.getByTestId('candidate-add-1').click();

    const box = (await page.getByTestId('candidate-modal').boundingBox())!;
    expect(Math.round(box.width)).toBe(360);
    expect(Math.round(box.y + box.height)).toBe(780);
    await expectNoSidewaysScroll(page);
    await expectAccessible(page);
  });
});
