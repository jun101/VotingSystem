import { expect, test } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { createBallot } from '../support/ballots';
import { createCandidate } from '../support/candidates';
import { createElection } from '../support/elections';
import { createVoter, readVoters } from '../support/voters';
import { waitUntilOpen } from '../support/voting';

/*
 * Slice 07 — voters of an open election: a voter who has not voted may be moved to another existing group; one who has
 * voted keeps theirs; a group that does not exist is not created. docs/api/voters/PATCH-voters-{voter}.md.
 * The election is scheduled to start in a moment and opened by the scheduler's minute command.
 */

test('moves a voter who has not voted, refuses a new group, and locks the group of one who voted [FR-SEC-06]', async ({
  page,
  browser,
}) => {
  test.setTimeout(300_000);

  await registerAndEnter(page);
  const starts = new Date(Date.now() + 25_000).toISOString();
  const ends = new Date(Date.now() + 2 * 24 * 3600 * 1000).toISOString();
  const election = await createElection(page, 'Électeurs en cours de vote', starts, ends);
  const ballot = await createBallot(page, election.id, 'Président(e)', { seats: 1, allow_blank: true });
  await createCandidate(page, ballot.id, 'Nadège', 'Pierre-Louis');
  const voted = await createVoter(page, election.id, 'Alice A', { group: '4e année' });
  await createVoter(page, election.id, 'Bob B', { group: '5e année' });

  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  const headers = { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
  const credential = await page.request.post(`/api/v1/voters/${voted.id}/credential`, { headers, data: {} });
  expect(credential.status()).toBe(201);
  const code = ((await credential.json()) as { data: { code: string } }).data.code;
  const schedule = await page.request.post(`/api/v1/elections/${election.id}/schedule`, {
    headers,
    data: { accept_warnings: true },
  });
  expect(schedule.status(), await schedule.text()).toBe(200);

  await waitUntilOpen(page, election.id);

  // Alice votes.
  const context = await browser.newContext({ locale: 'fr-FR' });
  const voter = await context.newPage();
  await voter.goto(`/vote/${election.id}`);
  await voter.getByTestId('vote-code-input').fill(code);
  await voter.getByTestId('vote-code-submit').click();
  await voter.getByTestId('vote-identity-yes').click();
  await voter.getByTestId('vote-candidate-1').click();
  await voter.getByRole('button', { name: 'Vérifier mon vote' }).click();
  await voter.getByTestId('vote-confirm').click();
  await expect(voter).toHaveURL(`/vote/${election.id}/done`);
  await context.close();

  await page.goto(`/admin/elections/${election.id}/voters`);
  const card = (name: string) => page.locator('[data-testid^="voter-card-"]', { hasText: name });
  const edit = (name: string) => card(name).locator('[data-testid^="voter-edit-"]');

  // Bob has not voted: another existing group is accepted.
  await edit('Bob B').click();
  await expect(page.getByTestId('voter-form-group')).toBeEnabled();
  await page.getByTestId('voter-form-group').fill('4e année');
  await page.getByTestId('voter-form-save').click();
  await expect(page.getByTestId('voter-modal')).toHaveCount(0);
  await expect(card('Bob B')).toContainText('4e année');
  expect((await readVoters(page, election.id)).find((row) => row.full_name === 'Bob B')?.group?.name).toBe('4e année');

  // A group that does not exist is not created in an open election: said beside the field.
  await edit('Bob B').click();
  await page.getByTestId('voter-form-group').fill('6e année');
  await page.getByTestId('voter-form-save').click();
  await expect(page.getByTestId('voter-form-error-group')).toBeVisible();
  await expect(page.getByTestId('voter-modal')).toBeVisible();
  await page.keyboard.press('Escape');

  // Alice has voted: her group cannot change, her other fields can.
  await edit('Alice A').click();
  await expect(page.getByTestId('voter-form-group')).toBeDisabled();
  await page.getByTestId('voter-form-name').fill('Alice Après');
  await page.getByTestId('voter-form-save').click();
  await expect(card('Alice Après')).toContainText('4e année');
});
