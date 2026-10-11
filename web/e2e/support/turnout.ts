import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { registerAndEnter } from './admin';
import { createBallot } from './ballots';
import { createCandidate } from './candidates';
import { createElection } from './elections';
import { createVoter } from './voters';

/*
 * Slice 14 helpers: an election that is really open, with six voters in two groups and a way to cast votes through
 * the voters' own API, so the organiser's pages have real numbers to show. Part of the acceptance harness.
 */

export const VOTERS = [
  { key: 'ada', name: 'Ada Joseph', group: '4e année' },
  { key: 'bob', name: 'Bob Louis', group: '4e année' },
  { key: 'cleo', name: 'Cléo Pierre', group: '4e année' },
  { key: 'dan', name: 'Dan Étienne', group: '5e année' },
  { key: 'eli', name: 'Eli Joseph', group: '5e année' },
  { key: 'fay', name: 'Fay Sans-groupe', group: null },
] as const;

export type VoterKey = (typeof VOTERS)[number]['key'];

export type TurnoutElection = {
  election: string;
  title: string;
  storageState: Awaited<ReturnType<BrowserContext['storageState']>>;
  codes: Record<VoterKey, string>;
};

async function headers(page: Page): Promise<Record<string, string>> {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

/** Creates and schedules an election starting in `startsInSeconds` and ending in three days. */
export async function prepareTurnout(browser: Browser, startsInSeconds = 25): Promise<TurnoutElection> {
  const context = await browser.newContext({ locale: 'fr-FR', timezoneId: 'America/Port-au-Prince' });
  const page = await context.newPage();
  await registerAndEnter(page);
  const starts = new Date(Date.now() + startsInSeconds * 1000).toISOString();
  const ends = new Date(Date.now() + 3 * 24 * 3600 * 1000).toISOString();
  const title = 'Participation du conseil';
  const election = await createElection(page, title, starts, ends);

  const ballot = await createBallot(page, election.id, 'Président(e)', { seats: 1, allow_blank: true });
  await createCandidate(page, ballot.id, 'Nadège', 'Pierre-Louis');
  await createCandidate(page, ballot.id, 'Jean-Marc', 'Désir');

  const codes = {} as Record<VoterKey, string>;
  for (const voter of VOTERS) {
    const created = await createVoter(page, election.id, voter.name, voter.group ? { group: voter.group } : {});
    const response = await page.request.post(`/api/v1/voters/${created.id}/credential`, { headers: await headers(page), data: {} });
    expect(response.status(), await response.text()).toBe(201);
    codes[voter.key] = ((await response.json()) as { data: { code: string } }).data.code;
  }

  const schedule = await page.request.post(`/api/v1/elections/${election.id}/schedule`, {
    headers: await headers(page),
    data: { accept_warnings: true },
  });
  expect(schedule.status(), await schedule.text()).toBe(200);

  const storageState = await context.storageState();
  await context.close();

  return { election: election.id, title, storageState, codes };
}

/** Casts a vote for the first candidate of every ballot, as the voter with this code, through the voters' API. */
export async function castVote(browser: Browser, election: string, code: string): Promise<void> {
  const context = await browser.newContext();
  const request = context.request;

  const session = await request.post(`/api/v1/vote/${election}/session`, { data: { code } });
  expect(session.status(), await session.text()).toBe(201);
  const ballots = (await (await request.get(`/api/v1/vote/${election}/ballots`)).json()) as {
    data: { ballots: { id: string; candidates: { id: string }[] }[] };
  };
  const body = { ballots: ballots.data.ballots.map((ballot) => ({ ballot: ballot.id, candidates: [ballot.candidates[0]!.id] })) };
  const submit = await request.post(`/api/v1/vote/${election}/submit`, { data: body });
  expect(submit.status(), await submit.text()).toBe(201);
  await context.close();
}
