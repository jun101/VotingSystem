import { expect, type Page } from '@playwright/test';

/*
 * Slice 06 helpers: ballots through the API, so a test can set up a page without clicking.
 * Part of the acceptance harness.
 */

export type Ballot = { id: string; title: string; position: number; seats: number; allow_blank: boolean };

async function headers(page: Page): Promise<Record<string, string>> {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

/** Adds a ballot to a draft election as the signed-in person. */
export async function createBallot(
  page: Page,
  election: string,
  title: string,
  extra: Record<string, unknown> = {},
): Promise<Ballot> {
  const response = await page.request.post(`/api/v1/elections/${election}/ballots`, {
    headers: await headers(page),
    data: { title, ...extra },
  });
  expect(response.status(), await response.text()).toBe(201);

  return ((await response.json()) as { data: Ballot }).data;
}

/** The ballots of an election as the API gives them, in order. */
export async function readBallots(page: Page, election: string): Promise<Ballot[]> {
  const response = await page.request.get(`/api/v1/elections/${election}/ballots?per_page=100`);
  expect(response.status()).toBe(200);

  return ((await response.json()) as { data: Ballot[] }).data;
}

/** The titles of the ballot cards on the page, top to bottom, left to right as they are in the document. */
export async function cardTitles(page: Page): Promise<string[]> {
  return page.locator('[data-testid^="ballot-title-"]').allTextContents();
}
