import { expect, type Page } from '@playwright/test';

/*
 * Slice 06c helpers: candidates through the API. Part of the acceptance harness.
 */

export type Candidate = {
  id: string;
  ballot: string;
  party: string | null;
  first_name: string;
  last_name: string;
  sex: 'male' | 'female';
  slogan: string | null;
  biography: string | null;
  position: number;
};

async function headers(page: Page): Promise<Record<string, string>> {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

/** Adds a candidate to a ballot as the signed-in person. */
export async function createCandidate(
  page: Page,
  ballot: string,
  firstName: string,
  lastName: string,
  extra: Record<string, unknown> = {},
): Promise<Candidate> {
  const response = await page.request.post(`/api/v1/ballots/${ballot}/candidates`, {
    headers: await headers(page),
    data: { first_name: firstName, last_name: lastName, sex: 'female', ...extra },
  });
  expect(response.status(), await response.text()).toBe(201);

  return ((await response.json()) as { data: Candidate }).data;
}

/** The candidates of every ballot of an election, from the ballots list, in order. */
export async function readCandidates(page: Page, election: string): Promise<Record<string, Candidate[]>> {
  const response = await page.request.get(`/api/v1/elections/${election}/ballots?per_page=100`);
  expect(response.status()).toBe(200);
  const body = (await response.json()) as { data: { id: string; candidates: Candidate[] }[] };

  return Object.fromEntries(body.data.map((ballot) => [ballot.id, ballot.candidates]));
}

/** The full names shown in the candidate rows of one ballot card (position n, from 1), in order. */
export async function candidateNames(page: Page, ballotPosition: number): Promise<string[]> {
  return page.locator(`[data-testid^="candidate-name-${ballotPosition}-"]`).allTextContents();
}
