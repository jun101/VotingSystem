import { expect, test, type Page } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { createBallot, readBallots } from '../support/ballots';
import { createCandidate } from '../support/candidates';
import { createElection, readElection } from '../support/elections';
import { createVoter, readGroups, readVoters } from '../support/voters';

/*
 * Slice 09 — group scope on ballots (A06 modal and cards, A08 groups card), loading voters from another election
 * (A08 dialog) and the checklist with scheduling (A05). docs/slices/09-scope-copy-schedule.md. API: docs/api/ballots/,
 * docs/api/groups/, docs/api/voters/POST-elections-{election}-voter-copies.md, docs/api/elections/ (checklist,
 * schedule, unschedule).
 */

const FUTURE_START = '2030-03-01T12:00:00Z';
const FUTURE_END = '2030-03-05T19:00:00Z';

async function ready(page: Page, title = 'Planification') {
  await registerAndEnter(page);
  const election = await createElection(page, title, FUTURE_START, FUTURE_END);
  return election;
}

/** A draft that passes the checklist: one ballot with two candidates, two voters. */
async function complete(page: Page, election: string) {
  const ballot = await createBallot(page, election, 'Président(e)');
  await createCandidate(page, ballot.id, 'Rose', 'Désir');
  await createCandidate(page, ballot.id, 'Jean', 'Pierre');
  await createVoter(page, election, 'Nadège Louis', { group: '4e année' });
  await createVoter(page, election, 'Kervens Étienne', { group: '5e année' });
  return ballot;
}

test.describe('group scope on the ballots page', () => {
  test('chooses the groups of a ballot in the modal and shows them on the card [FR-BAL-03, FR-BAL-05]', async ({ page }) => {
    const election = await ready(page);
    for (const n of [1, 2, 3, 4, 5, 6]) await createVoter(page, election.id, `Quatrième ${n}`, { group: '4e année' });
    await createVoter(page, election.id, 'Cinquième 1', { group: '5e année' });
    await page.goto(`/admin/elections/${election.id}/ballots`);

    await page.getByTestId('ballots-add').click();
    await page.getByTestId('ballot-form-title').fill('Délégué de classe');
    await page.getByTestId('ballot-form-scope-group').check();
    await expect(page.getByTestId('ballot-form-groups')).toBeVisible();
    await page.getByTestId('ballot-form-group-1').click();
    await expect(page.getByTestId('ballot-form-group-1')).toHaveAttribute('aria-pressed', 'true');
    await page.getByTestId('ballot-form-save').click();

    await expect(page.getByTestId('ballot-form')).toHaveCount(0);
    await expect(page.getByTestId('ballot-scope-1')).toContainText('6');
    await expect(page.getByTestId('ballot-groups-1')).toContainText('4e année');
    const saved = await readBallots(page, election.id);
    expect(saved[0]).toMatchObject({ title: 'Délégué de classe', scope: 'group', eligible_voters: 6 });
  });

  test('asks for at least one group when the scope is group [FR-BAL-03]', async ({ page }) => {
    const election = await ready(page);
    await createVoter(page, election.id, 'Rose', { group: '4e année' });
    await page.goto(`/admin/elections/${election.id}/ballots`);

    await page.getByTestId('ballots-add').click();
    await page.getByTestId('ballot-form-title').fill('Délégué');
    await page.getByTestId('ballot-form-scope-group').check();
    await page.getByTestId('ballot-form-save').click();

    await expect(page.getByTestId('ballot-form-error-groups')).toBeVisible();
    await expect(page.getByTestId('ballot-form')).toBeVisible();
    expect(await readBallots(page, election.id)).toHaveLength(0);
  });

  test('warns on the card when a group ballot has fewer than 5 voters [FR-BAL-06]', async ({ page }) => {
    const election = await ready(page);
    await createVoter(page, election.id, 'Rose', { group: '6e année' });
    await createBallot(page, election.id, 'Délégué 6e', { scope: 'group', groups: [(await readGroups(page, election.id))[0].id] });
    await page.goto(`/admin/elections/${election.id}/ballots`);

    await expect(page.getByTestId('ballot-voters-warning-1')).toBeVisible();
  });

  test('keeps a group a ballot uses: the delete is disabled and the card of the groups says why [FR-VOT-08]', async ({ page }) => {
    const election = await ready(page);
    await createVoter(page, election.id, 'Rose', { group: '4e année' });
    const groups = await readGroups(page, election.id);
    await createBallot(page, election.id, 'Délégué', { scope: 'group', groups: [groups[0].id] });
    await page.goto(`/admin/elections/${election.id}/voters`);

    await expect(page.getByTestId('group-ballots-1')).toContainText('1');
    await expect(page.getByTestId('group-delete-1')).toBeDisabled();
  });

  test('merges a group a ballot uses and names the ballot in the dialog [FR-VOT-08]', async ({ page }) => {
    const election = await ready(page);
    await createVoter(page, election.id, 'Rose', { group: '4e année' });
    await createVoter(page, election.id, 'Jean', { group: '5e année' });
    const groups = await readGroups(page, election.id);
    const ballot = await createBallot(page, election.id, 'Délégué', { scope: 'group', groups: [groups[0].id] });
    await page.goto(`/admin/elections/${election.id}/voters`);

    await page.getByTestId('group-merge-1').click();
    await expect(page.getByTestId('group-merge-ballots')).toContainText('Délégué');
    await page.getByTestId('group-merge-into').selectOption({ label: '5e année' });
    await page.getByTestId('group-merge-confirm').click();

    await expect(page.getByTestId('group-merge-dialog')).toHaveCount(0);
    const after = await readBallots(page, election.id);
    expect(after.find((b) => b.id === ballot.id)?.groups?.map((g) => g.name)).toEqual(['5e année']);
  });
});

test.describe('loading voters from another election', () => {
  test('picks a source, some groups, renames one, and shows what was copied [FR-VOT-05]', async ({ page }) => {
    await registerAndEnter(page);
    const source = await createElection(page, 'Année dernière', FUTURE_START, FUTURE_END);
    await createVoter(page, source.id, 'Rose-Marie Désir', { group: '4e année', identifier: 'E-1' });
    await createVoter(page, source.id, 'Jean-Baptiste Pierre', { group: '4e année', identifier: 'E-2' });
    await createVoter(page, source.id, 'Nadège Louis', { group: '5e année', identifier: 'E-3' });
    const target = await createElection(page, 'Cette année', FUTURE_START, FUTURE_END);
    await page.goto(`/admin/elections/${target.id}/voters`);

    await page.getByTestId('voters-copy').click();
    await expect(page.getByTestId('copy-dialog')).toBeVisible();
    await page.getByTestId('copy-source').selectOption({ label: 'Année dernière' });
    await page.getByTestId('copy-groups-some').check();
    await page.getByTestId('copy-group-1').check();
    await page.getByTestId('copy-rename-1').fill('5e année');
    await page.getByTestId('copy-submit').click();

    await expect(page.getByTestId('copy-result')).toBeVisible();
    await expect(page.getByTestId('copy-result-copied')).toContainText('2');
    await expect(page.getByTestId('copy-result-skipped')).toContainText('0');
    await page.getByTestId('copy-close').click();
    await expect(page.getByTestId('voters-count')).toContainText('2');
    const groups = await readGroups(page, target.id);
    expect(groups.map((group) => group.name)).toEqual(['5e année']);
    expect(await readVoters(page, target.id)).toHaveLength(2);
  });

  test('counts the voters skipped because of a repeated identifier [FR-VOT-05]', async ({ page }) => {
    await registerAndEnter(page);
    const source = await createElection(page, 'Source', FUTURE_START, FUTURE_END);
    await createVoter(page, source.id, 'Rose', { identifier: 'E-1' });
    await createVoter(page, source.id, 'Jean', { identifier: 'E-2' });
    const target = await createElection(page, 'Cible', FUTURE_START, FUTURE_END);
    await createVoter(page, target.id, 'Déjà là', { identifier: 'E-1' });
    await page.goto(`/admin/elections/${target.id}/voters`);

    await page.getByTestId('voters-copy').click();
    await page.getByTestId('copy-source').selectOption({ label: 'Source' });
    await page.getByTestId('copy-submit').click();

    await expect(page.getByTestId('copy-result-copied')).toContainText('1');
    await expect(page.getByTestId('copy-result-skipped')).toContainText('1');
  });
});

test.describe('the checklist and the scheduling (A05)', () => {
  test('lists what blocks the scheduling and disables the button [FR-ELEC-04]', async ({ page }) => {
    const election = await ready(page);
    await page.goto(`/admin/elections/${election.id}`);

    await expect(page.getByTestId('election-checklist')).toBeVisible();
    await expect(page.getByTestId('checklist-blockers')).toBeVisible();
    await expect(page.locator('[data-testid^="checklist-blocker-"]')).toHaveCount(2);
    await expect(page.getByTestId('election-schedule-button')).toBeDisabled();
  });

  test('schedules a ready election after a confirmation, and locks the ballots [FR-ELEC-03]', async ({ page }) => {
    const election = await ready(page);
    await complete(page, election.id);
    await page.goto(`/admin/elections/${election.id}`);
    await expect(page.getByTestId('checklist-ok')).toBeVisible();

    await page.getByTestId('election-schedule-button').click();
    await expect(page.getByTestId('schedule-dialog')).toBeVisible();
    await page.getByTestId('schedule-confirm').click();

    await expect(page.getByTestId('schedule-dialog')).toHaveCount(0);
    await expect(page.getByTestId('election-summary-status')).toHaveAttribute('data-status', 'scheduled');
    expect(((await readElection(page, election.id)) as { status: string }).status).toBe('scheduled');
    await page.goto(`/admin/elections/${election.id}/ballots`);
    await expect(page.getByTestId('ballots-locked-notice')).toBeVisible();
    await expect(page.getByTestId('ballots-add')).toHaveCount(0);
  });

  test('opens the schedule dialog from the Planifier step, and goes to the checklist when something blocks [FR-ELEC-03]', async ({ page }) => {
    const election = await ready(page);
    await page.goto(`/admin/elections/${election.id}`);
    await expect(page.getByTestId('checklist-blockers')).toBeVisible();

    await page.getByTestId('election-step-3').click();
    await expect(page.getByTestId('schedule-dialog')).toHaveCount(0);
    await expect(page.getByTestId('election-checklist')).toBeInViewport();

    await complete(page, election.id);
    await page.goto(`/admin/elections/${election.id}`);
    await expect(page.getByTestId('checklist-ok')).toBeVisible();
    await page.getByTestId('election-step-3').click();
    await expect(page.getByTestId('schedule-dialog')).toBeVisible();
    await expect(page).not.toHaveURL(/#/);
  });

  test('needs a tick when there are warnings [FR-ELEC-04]', async ({ page }) => {
    const election = await ready(page);
    const ballot = await createBallot(page, election.id, 'Un seul candidat');
    await createCandidate(page, ballot.id, 'Rose', 'Désir');
    await createVoter(page, election.id, 'Nadège Louis');
    await page.goto(`/admin/elections/${election.id}`);
    await expect(page.locator('[data-testid^="checklist-warning-"]')).toHaveCount(1);

    await page.getByTestId('election-schedule-button').click();
    await expect(page.getByTestId('schedule-confirm')).toBeDisabled();
    await page.getByTestId('schedule-accept-warnings').check();
    await expect(page.getByTestId('schedule-confirm')).toBeEnabled();
    await page.getByTestId('schedule-confirm').click();

    await expect(page.getByTestId('election-summary-status')).toHaveAttribute('data-status', 'scheduled');
  });

  test('takes a scheduled election back to draft [FR-ELEC-03]', async ({ page }) => {
    const election = await ready(page);
    await complete(page, election.id);
    await page.goto(`/admin/elections/${election.id}`);
    await page.getByTestId('election-schedule-button').click();
    await page.getByTestId('schedule-confirm').click();
    await expect(page.getByTestId('election-summary-status')).toHaveAttribute('data-status', 'scheduled');

    await page.getByTestId('election-unschedule-button').click();
    await expect(page.getByTestId('unschedule-dialog')).toBeVisible();
    await page.getByTestId('unschedule-confirm').click();

    await expect(page.getByTestId('election-summary-status')).toHaveAttribute('data-status', 'draft');
    expect(((await readElection(page, election.id)) as { status: string }).status).toBe('draft');
  });
});

test.describe('quality', () => {
  test('is accessible and never scrolls sideways: the modal with groups, the copy dialog, the checklist [NFR-UX-02, NFR-UX-03]', async ({ page }) => {
    const election = await ready(page);
    await complete(page, election.id);
    await page.goto(`/admin/elections/${election.id}`);
    await expectAccessible(page);
    await expectNoSidewaysScroll(page);

    await page.goto(`/admin/elections/${election.id}/ballots`);
    await page.getByTestId('ballots-add').click();
    await page.getByTestId('ballot-form-scope-group').check();
    await expectAccessible(page);
    await expectNoSidewaysScroll(page);

    await page.goto(`/admin/elections/${election.id}/voters`);
    await page.getByTestId('voters-copy').click();
    await expectAccessible(page);
    await expectNoSidewaysScroll(page);
  });
});
