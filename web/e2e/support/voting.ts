import { expect, type Browser, type Page } from '@playwright/test';
import { registerAndEnter } from './admin';
import { createBallot } from './ballots';
import { createCandidate } from './candidates';
import { createElection } from './elections';
import { createVoter, readGroups } from './voters';

/*
 * Slice 12 helpers: an election that is really open (scheduled, then opened by the scheduler's minute command), with
 * its voters' codes. Part of the acceptance harness.
 */

export type Voting = {
  election: string;
  title: string;
  codes: { wideline: string; paul: string; marc: string; extra: string[] };
};

async function headers(page: Page): Promise<Record<string, string>> {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

/** The phase the public page of an election reports. */
export async function phaseOf(page: Page, election: string): Promise<string> {
  const response = await page.request.get(`/api/v1/vote/${election}`);
  expect(response.status()).toBe(200);

  return ((await response.json()) as { data: { phase: string } }).data.phase;
}

/**
 * Creates and schedules an election that starts `startsInSeconds` after now (ends in two days) with two ballots
 * (a general one with two candidates that allows a blank vote, one for the group "4e année" with two candidates), voters
 * Wideline (4e année), Paul (5e année), Marc (no group) and `extra` more voters in 4e année, and reads their codes by
 * reissuing each once. Returns when the election is scheduled; call `waitUntilOpen` to wait for the scheduler.
 */
export async function prepareElection(browser: Browser, startsInSeconds = 25, extra = 4): Promise<Voting> {
  const context = await browser.newContext({ locale: 'fr-FR', timezoneId: 'America/Port-au-Prince' });
  const page = await context.newPage();
  await registerAndEnter(page);
  const starts = new Date(Date.now() + startsInSeconds * 1000).toISOString();
  const ends = new Date(Date.now() + 2 * 24 * 3600 * 1000).toISOString();
  const title = 'Conseil des élèves';
  const election = await createElection(page, title, starts, ends);

  const wideline = await createVoter(page, election.id, 'Wideline Joseph', { group: '4e année' });
  const paul = await createVoter(page, election.id, 'Paul Étienne', { group: '5e année' });
  const marc = await createVoter(page, election.id, 'Marc Sans-groupe');
  const more = [];
  for (let i = 1; i <= extra; i += 1) more.push(await createVoter(page, election.id, `Élève ${i}`, { group: '4e année' }));
  const fourth = (await readGroups(page, election.id)).find((group) => group.name === '4e année');
  expect(fourth).toBeTruthy();

  const president = await createBallot(page, election.id, 'Président(e)', { seats: 1, allow_blank: true });
  await createCandidate(page, president.id, 'Nadège', 'Pierre-Louis', { slogan: 'Une école qui nous écoute' });
  await createCandidate(page, president.id, 'Jean-Marc', 'Désir', { slogan: 'Plus de sport' });
  const delegate = await createBallot(page, election.id, 'Délégué(e) de classe', {
    seats: 1,
    allow_blank: false,
    scope: 'group',
    groups: [fourth!.id],
  });
  await createCandidate(page, delegate.id, 'Ricardo', 'Saint-Fleur');
  await createCandidate(page, delegate.id, 'Marie', 'Augustin');

  const code = async (voter: string): Promise<string> => {
    const response = await page.request.post(`/api/v1/voters/${voter}/credential`, { headers: await headers(page), data: {} });
    expect(response.status(), await response.text()).toBe(201);

    return ((await response.json()) as { data: { code: string } }).data.code;
  };
  const codes = {
    wideline: await code(wideline.id),
    paul: await code(paul.id),
    marc: await code(marc.id),
    extra: await Promise.all(more.map((voter) => code(voter.id))),
  };

  const schedule = await page.request.post(`/api/v1/elections/${election.id}/schedule`, {
    headers: await headers(page),
    data: { accept_warnings: true },
  });
  expect(schedule.status(), await schedule.text()).toBe(200);
  await context.close();

  return { election: election.id, title, codes };
}

/** Waits for the scheduler's minute command to open the election (at most about two minutes). */
export async function waitUntilOpen(page: Page, election: string): Promise<void> {
  await expect.poll(() => phaseOf(page, election), { timeout: 150_000, intervals: [2_000] }).toBe('open');
}
