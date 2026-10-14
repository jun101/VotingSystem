import { expect, type Page } from '@playwright/test';

/*
 * Slice 07 helpers: voters and groups through the API. Part of the acceptance harness.
 */

export type Voter = {
  id: string;
  full_name: string;
  group: { id: string; name: string } | null;
  identifier: string | null;
  email: string | null;
  phone: string | null;
};

export type Group = { id: string; name: string; voters_count: number };

async function headers(page: Page): Promise<Record<string, string>> {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

/** Adds a voter to an election as the signed-in person. */
export async function createVoter(
  page: Page,
  election: string,
  fullName: string,
  extra: Record<string, unknown> = {},
): Promise<Voter> {
  const response = await page.request.post(`/api/v1/elections/${election}/voters`, {
    headers: await headers(page),
    data: { full_name: fullName, ...extra },
  });
  expect(response.status(), await response.text()).toBe(201);

  return ((await response.json()) as { data: Voter }).data;
}

/** Adds an empty group. */
export async function createGroup(page: Page, election: string, name: string): Promise<Group> {
  const response = await page.request.post(`/api/v1/elections/${election}/groups`, {
    headers: await headers(page),
    data: { name },
  });
  expect(response.status(), await response.text()).toBe(201);

  return ((await response.json()) as { data: Group }).data;
}

/** The voters of an election as the API gives them (one page of up to 100), by name. */
export async function readVoters(page: Page, election: string, query = ''): Promise<Voter[]> {
  const response = await page.request.get(`/api/v1/elections/${election}/voters?per_page=100${query}`);
  expect(response.status()).toBe(200);

  return ((await response.json()) as { data: Voter[] }).data;
}

/** The groups of an election as the API gives them, by name. */
export async function readGroups(page: Page, election: string): Promise<Group[]> {
  const response = await page.request.get(`/api/v1/elections/${election}/groups?per_page=100`);
  expect(response.status()).toBe(200);

  return ((await response.json()) as { data: Group[] }).data;
}

/** The names shown on the voter cards of the page, top to bottom. */
export async function cardNames(page: Page): Promise<string[]> {
  return page.locator('[data-testid^="voter-name-"]').allTextContents();
}
