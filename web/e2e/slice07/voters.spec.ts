import { expect, test, type Page } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { createElection } from '../support/elections';
import { cardNames, createGroup, createVoter, readGroups, readVoters } from '../support/voters';

/*
 * Slice 07 — the voters page (screen A08): voter cards, search, group filter, pages, the voter modal and the
 * groups card. docs/slices/07-voters-groups.md. API: docs/api/voters/ and docs/api/groups/. Design:
 * docs/design/frontend.md item 13, mockups material-voters.html and material-voter-modal.html.
 */

async function open(page: Page, title = 'Électeurs') {
  await registerAndEnter(page);
  const election = await createElection(page, title);
  await page.goto(`/admin/elections/${election.id}/voters`);
  return election;
}

test.describe('an election with no voter', () => {
  test('shows the page with an invitation, the counts at zero and an empty groups card [FR-VOT-01]', async ({ page }) => {
    await open(page);

    await expect(page.getByTestId('voters-page')).toBeVisible();
    await expect(page.getByTestId('voters-empty')).toBeVisible();
    await expect(page.getByTestId('voters-count')).toContainText('0');
    await expect(page.getByTestId('voter-add')).toBeVisible();
    await expect(page.getByTestId('groups-card')).toBeVisible();
    await expect(page.locator('[data-testid^="voter-card-"]')).toHaveCount(0);
    await expect(page.locator('[data-testid^="group-row-"]')).toHaveCount(0);
  });

  test('is reached from the election page next steps and the side menu stays on Élections [FR-VOT-01]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Chemin');
    await page.goto(`/admin/elections/${election.id}`);

    await page.getByTestId('election-step-2').click();

    await expect(page).toHaveURL(`/admin/elections/${election.id}/voters`);
    await expect(page.getByTestId('voters-page')).toBeVisible();
  });
});

test.describe('adding a voter in a modal', () => {
  test('opens from the hero button, saves, closes and the card appears without leaving the page [FR-VOT-02]', async ({ page }) => {
    const election = await open(page);
    const url = page.url();

    await page.getByTestId('voter-add').click();
    await expect(page.getByTestId('voter-modal')).toBeVisible();
    await expect(page.getByTestId('voter-modal')).toHaveAttribute('role', 'dialog');
    await page.getByTestId('voter-form-name').fill('Rose-Marie Désir');
    await page.getByTestId('voter-form-group').fill('4e année');
    await page.getByTestId('voter-form-identifier').fill('E-2041');
    await page.getByTestId('voter-form-email').fill('rm.desir@example.ht');
    await page.getByTestId('voter-form-phone').fill('+509 3712 4455');
    await page.getByTestId('voter-form-save').click();

    await expect(page.getByTestId('voter-modal')).toHaveCount(0);
    expect(page.url()).toBe(url);
    await expect(page.getByTestId('voter-name-1')).toHaveText('Rose-Marie Désir');
    await expect(page.getByTestId('voter-group-1')).toHaveText('4e année');
    await expect(page.getByTestId('voter-identifier-1')).toContainText('E-2041');
    await expect(page.getByTestId('voters-count')).toContainText('1');
    await expect(page.getByTestId('group-name-1')).toHaveText('4e année');
    await expect(page.getByTestId('group-count-1')).toContainText('1');
    const saved = await readVoters(page, election.id);
    expect(saved).toHaveLength(1);
    expect(saved[0]).toMatchObject({ full_name: 'Rose-Marie Désir', identifier: 'E-2041', email: 'rm.desir@example.ht' });
  });

  test('"Enregistrer et ajouter un autre" keeps the modal open, cleared, with the group kept [FR-VOT-02]', async ({ page }) => {
    const election = await open(page);

    await page.getByTestId('voter-add').click();
    await page.getByTestId('voter-form-name').fill('Premier');
    await page.getByTestId('voter-form-group').fill('5e année');
    await page.getByTestId('voter-form-save-another').click();

    await expect(page.getByTestId('voter-modal')).toBeVisible();
    await expect(page.getByTestId('voter-form-name')).toHaveValue('');
    await expect(page.getByTestId('voter-form-group')).toHaveValue('5e année');
    await page.getByTestId('voter-form-name').fill('Second');
    await page.getByTestId('voter-form-save').click();

    await expect(page.getByTestId('voter-modal')).toHaveCount(0);
    expect(await cardNames(page)).toEqual(['Premier', 'Second']);
    expect(await readGroups(page, election.id)).toHaveLength(1);
  });

  test('shows a field error beside the field and stays open on a duplicate identifier [FR-VOT-01]', async ({ page }) => {
    const election = await open(page);
    await createVoter(page, election.id, 'Déjà là', { identifier: 'E-1' });
    await page.reload();

    await page.getByTestId('voter-add').click();
    await page.getByTestId('voter-form-name').fill('Doublon');
    await page.getByTestId('voter-form-identifier').fill('e-1');
    await page.getByTestId('voter-form-save').click();

    await expect(page.getByTestId('voter-modal')).toBeVisible();
    await expect(page.getByTestId('voter-form-error-identifier')).toBeVisible();
    expect(await readVoters(page, election.id)).toHaveLength(1);
  });

  test('requires a name before sending [FR-VOT-01]', async ({ page }) => {
    await open(page);

    await page.getByTestId('voter-add').click();
    await page.getByTestId('voter-form-save').click();

    await expect(page.getByTestId('voter-form-error-full_name')).toBeVisible();
    await expect(page.getByTestId('voter-modal')).toBeVisible();
  });

  test('closes with Escape, keeps focus inside, and returns focus to the button [NFR-UX-03]', async ({ page }) => {
    await open(page);

    await page.getByTestId('voter-add').click();
    await expect(page.getByTestId('voter-form-name')).toBeFocused();
    await page.keyboard.press('Escape');

    await expect(page.getByTestId('voter-modal')).toHaveCount(0);
    await expect(page.getByTestId('voter-add')).toBeFocused();
  });
});

test.describe('editing and deleting a voter', () => {
  test('edits a voter in the same modal, filled, and the card updates [FR-VOT-06]', async ({ page }) => {
    const election = await open(page);
    await createVoter(page, election.id, 'Avant', { group: '4e année', email: 'avant@example.ht' });
    await page.reload();

    await page.getByTestId('voter-edit-1').click();
    await expect(page.getByTestId('voter-form-name')).toHaveValue('Avant');
    await expect(page.getByTestId('voter-form-group')).toHaveValue('4e année');
    await expect(page.getByTestId('voter-form-email')).toHaveValue('avant@example.ht');
    await page.getByTestId('voter-form-name').fill('Après');
    await page.getByTestId('voter-form-group').fill('');
    await page.getByTestId('voter-form-save').click();

    await expect(page.getByTestId('voter-name-1')).toHaveText('Après');
    await expect(page.getByTestId('voter-group-1')).toContainText('Sans groupe');
    expect((await readVoters(page, election.id))[0]).toMatchObject({ full_name: 'Après', group: null });
  });

  test('deletes a voter after a confirmation naming the voter, and can be cancelled [FR-VOT-06]', async ({ page }) => {
    const election = await open(page);
    await createVoter(page, election.id, 'Partant');
    await createVoter(page, election.id, 'Reste');
    await page.reload();

    await page.getByTestId('voter-delete-1').click();
    await expect(page.getByTestId('voter-delete-dialog')).toContainText('Partant');
    await page.getByTestId('voter-delete-cancel').click();
    await expect(page.getByTestId('voter-delete-dialog')).toHaveCount(0);
    expect(await readVoters(page, election.id)).toHaveLength(2);

    await page.getByTestId('voter-delete-1').click();
    await page.getByTestId('voter-delete-confirm').click();

    await expect(page.getByTestId('voter-delete-dialog')).toHaveCount(0);
    expect(await cardNames(page)).toEqual(['Reste']);
    expect((await readVoters(page, election.id)).map((voter) => voter.full_name)).toEqual(['Reste']);
  });
});

test.describe('searching, filtering and paging', () => {
  test('searches by name, identifier or email and shows an empty-result message [FR-VOT-06]', async ({ page }) => {
    const election = await open(page);
    await createVoter(page, election.id, 'Rose-Marie Désir', { identifier: 'E-1' });
    await createVoter(page, election.id, 'Jean Pierre', { email: 'jean@example.ht' });
    await page.reload();

    await page.getByTestId('voters-search').fill('rose');
    await expect.poll(() => cardNames(page)).toEqual(['Rose-Marie Désir']);
    await page.getByTestId('voters-search').fill('jean@');
    await expect.poll(() => cardNames(page)).toEqual(['Jean Pierre']);
    await page.getByTestId('voters-search').fill('zzz');
    await expect(page.getByTestId('voters-no-match')).toBeVisible();
  });

  test('filters by group from the chips and from the groups card, and by "no group" [FR-VOT-06]', async ({ page }) => {
    const election = await open(page);
    await createVoter(page, election.id, 'Un', { group: '4e année' });
    await createVoter(page, election.id, 'Deux', { group: '5e année' });
    await createVoter(page, election.id, 'Trois');
    await page.reload();

    await page.getByTestId('voters-filter-1').click();
    await expect.poll(() => cardNames(page)).toEqual(['Un']);
    await page.getByTestId('voters-filter-none').click();
    await expect.poll(() => cardNames(page)).toEqual(['Trois']);
    await page.getByTestId('voters-filter-all').click();
    await expect.poll(() => cardNames(page)).toEqual(['Deux', 'Trois', 'Un'].sort((a, b) => a.localeCompare(b)));
  });

  test('pages by 24 with a range text and next and previous buttons [FR-VOT-06]', async ({ page }) => {
    const election = await open(page);
    for (let index = 1; index <= 26; index += 1) {
      await createVoter(page, election.id, `Électeur ${String(index).padStart(2, '0')}`);
    }
    await page.reload();

    await expect(page.locator('[data-testid^="voter-card-"]')).toHaveCount(24);
    await expect(page.getByTestId('voters-range')).toContainText('24');
    await expect(page.getByTestId('voters-range')).toContainText('26');
    await page.getByTestId('voters-next').click();
    await expect(page.locator('[data-testid^="voter-card-"]')).toHaveCount(2);
    await expect(page.getByTestId('voter-name-1')).toHaveText('Électeur 25');
    await page.getByTestId('voters-prev').click();
    await expect(page.locator('[data-testid^="voter-card-"]')).toHaveCount(24);
  });
});

test.describe('the groups card', () => {
  test('lists the groups with their counts and creates one in a small modal [FR-VOT-08]', async ({ page }) => {
    const election = await open(page);
    await createVoter(page, election.id, 'Un', { group: '4e année' });
    await page.reload();

    await expect(page.getByTestId('group-name-1')).toHaveText('4e année');
    await expect(page.getByTestId('group-count-1')).toContainText('1');
    await page.getByTestId('group-new').click();
    await page.getByTestId('group-form-name').fill('Terminale A');
    await page.getByTestId('group-form-save').click();

    await expect(page.getByTestId('group-modal')).toHaveCount(0);
    await expect(page.getByTestId('group-name-2')).toHaveText('Terminale A');
    expect((await readGroups(page, election.id)).map((group) => group.name)).toEqual(['4e année', 'Terminale A']);
  });

  test('renames a group and its voters follow it [FR-VOT-08]', async ({ page }) => {
    const election = await open(page);
    await createVoter(page, election.id, 'Un', { group: '4e année' });
    await page.reload();

    await page.getByTestId('group-rename-1').click();
    await expect(page.getByTestId('group-form-name')).toHaveValue('4e année');
    await page.getByTestId('group-form-name').fill('6e année');
    await page.getByTestId('group-form-save').click();

    await expect(page.getByTestId('group-name-1')).toHaveText('6e année');
    await expect(page.getByTestId('voter-group-1')).toHaveText('6e année');
  });

  test('refuses to delete a group in use (the button is disabled) and deletes an empty one after a confirmation [FR-VOT-08]', async ({ page }) => {
    const election = await open(page);
    await createVoter(page, election.id, 'Un', { group: '4e année' });
    await createGroup(page, election.id, 'Vide');
    await page.reload();

    await expect(page.getByTestId('group-delete-1')).toBeDisabled();
    await expect(page.getByTestId('group-delete-2')).toBeEnabled();
    await page.getByTestId('group-delete-2').click();
    await expect(page.getByTestId('group-delete-dialog')).toContainText('Vide');
    await page.getByTestId('group-delete-confirm').click();

    await expect(page.getByTestId('group-delete-dialog')).toHaveCount(0);
    await expect(page.locator('[data-testid^="group-row-"]')).toHaveCount(1);
    expect((await readGroups(page, election.id)).map((group) => group.name)).toEqual(['4e année']);
  });

  test('merges a group into another: the voters move, the merged group goes away [FR-VOT-08]', async ({ page }) => {
    const election = await open(page);
    await createVoter(page, election.id, 'Un', { group: '4e année' });
    await createVoter(page, election.id, 'Deux', { group: '5e année' });
    await page.reload();

    await page.getByTestId('group-merge-1').click();
    await expect(page.getByTestId('group-merge-dialog')).toBeVisible();
    await page.getByTestId('group-merge-into').selectOption({ label: '5e année' });
    await page.getByTestId('group-merge-confirm').click();

    await expect(page.getByTestId('group-merge-dialog')).toHaveCount(0);
    await expect(page.locator('[data-testid^="group-row-"]')).toHaveCount(1);
    await expect(page.getByTestId('group-count-1')).toContainText('2');
    const groups = await readGroups(page, election.id);
    expect(groups).toHaveLength(1);
    expect(groups[0]).toMatchObject({ name: '5e année', voters_count: 2 });
  });
});

test.describe('quality', () => {
  test('is accessible and never scrolls sideways, with the modal open or closed [NFR-UX-02, NFR-UX-03]', async ({ page }) => {
    const election = await open(page);
    for (const name of ['Rose-Marie Désir', 'Jean-Baptiste Pierre', 'Nadège Louis']) {
      await createVoter(page, election.id, name, { group: '4e année', identifier: `E-${name.length}` });
    }
    await page.reload();

    await expectAccessible(page);
    await expectNoSidewaysScroll(page);
    await page.getByTestId('voter-add').click();
    await expectAccessible(page);
    await expectNoSidewaysScroll(page);
  });

  test('shows two cards per row from a tablet width and one on a phone [FR-VOT-06]', async ({ page }, info) => {
    const election = await open(page);
    for (const name of ['A', 'B', 'C', 'D']) await createVoter(page, election.id, name);
    await page.reload();

    const first = await page.getByTestId('voter-card-1').boundingBox();
    const second = await page.getByTestId('voter-card-2').boundingBox();
    expect(first && second).toBeTruthy();
    if (info.project.name === 'phone') {
      expect(second!.y).toBeGreaterThan(first!.y + first!.height - 1);
    } else {
      expect(Math.abs(second!.y - first!.y)).toBeLessThan(2);
      expect(second!.x).toBeGreaterThan(first!.x + first!.width - 1);
    }
  });
});
