import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { registerAndEnter } from './admin';
import { createBallot } from './ballots';
import { createCandidate } from './candidates';
import { createElection } from './elections';
import { createVoter } from './voters';

/*
 * Slice 13 helpers: an election that is really open (scheduled, then opened by the scheduler's minute command), with
 * the admin's session kept so each test can open a page as the organiser. Part of the acceptance harness.
 */

export type Closing = {
  election: string;
  title: string;
  /** The organiser's cookies, to give to `browser.newContext({ storageState })`. */
  storageState: Awaited<ReturnType<BrowserContext['storageState']>>;
  /** The code of a voter of the election, for the voter's side of the closing. */
  code: string;
  /** The end of the election as scheduled (UTC, ISO). */
  endsAt: string;
};

async function headers(page: Page): Promise<Record<string, string>> {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

/** Creates and schedules an election that starts in `startsInSeconds` and ends in three days, with one ballot of two candidates and two voters. */
export async function prepareClosing(browser: Browser, startsInSeconds = 25): Promise<Closing> {
  const context = await browser.newContext({ locale: 'fr-FR', timezoneId: 'America/Port-au-Prince' });
  const page = await context.newPage();
  await registerAndEnter(page);
  const starts = new Date(Date.now() + startsInSeconds * 1000).toISOString();
  const endsAt = new Date(Date.now() + 3 * 24 * 3600 * 1000).toISOString();
  const title = 'Clôture du conseil';
  const election = await createElection(page, title, starts, endsAt);

  const ballot = await createBallot(page, election.id, 'Président(e)', { seats: 1, allow_blank: true });
  await createCandidate(page, ballot.id, 'Nadège', 'Pierre-Louis');
  await createCandidate(page, ballot.id, 'Jean-Marc', 'Désir');
  const voter = await createVoter(page, election.id, 'Wideline Joseph', { group: '4e année' });
  await createVoter(page, election.id, 'Paul Étienne', { group: '5e année' });

  const credential = await page.request.post(`/api/v1/voters/${voter.id}/credential`, { headers: await headers(page), data: {} });
  expect(credential.status(), await credential.text()).toBe(201);
  const code = ((await credential.json()) as { data: { code: string } }).data.code;

  const schedule = await page.request.post(`/api/v1/elections/${election.id}/schedule`, {
    headers: await headers(page),
    data: { accept_warnings: true },
  });
  expect(schedule.status(), await schedule.text()).toBe(200);

  const storageState = await context.storageState();
  await context.close();

  return { election: election.id, title, storageState, code, endsAt };
}

/** The value of a `datetime-local` field moved by whole days, without going through a time zone. */
export function shiftLocal(value: string, days: number): string {
  const [date, time] = value.split('T');
  const [year, month, day] = date!.split('-').map(Number);
  const moved = new Date(Date.UTC(year!, month! - 1, day! + days));
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${moved.getUTCFullYear()}-${pad(moved.getUTCMonth() + 1)}-${pad(moved.getUTCDate())}T${time}`;
}
