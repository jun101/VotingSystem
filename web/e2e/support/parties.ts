import { expect, type Page } from '@playwright/test';

/*
 * Slice 06b helpers: parties through the API. Part of the acceptance harness.
 */

export type Party = { id: string; name: string; acronym: string | null; colour: string };

async function headers(page: Page): Promise<Record<string, string>> {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

/** Adds a party to a draft election as the signed-in person. */
export async function createParty(
  page: Page,
  election: string,
  name: string,
  colour = '#5468D4',
  acronym: string | null = null,
): Promise<Party> {
  const response = await page.request.post(`/api/v1/elections/${election}/parties`, {
    headers: await headers(page),
    data: { name, colour, acronym },
  });
  expect(response.status(), await response.text()).toBe(201);

  return ((await response.json()) as { data: Party }).data;
}

/** The parties of an election as the API gives them, by name. */
export async function readParties(page: Page, election: string): Promise<Party[]> {
  const response = await page.request.get(`/api/v1/elections/${election}/parties?per_page=100`);
  expect(response.status()).toBe(200);

  return ((await response.json()) as { data: Party[] }).data;
}

/** The names shown in the party rows of the page, top to bottom. */
export async function rowNames(page: Page): Promise<string[]> {
  return page.locator('[data-testid^="party-name-"]').allTextContents();
}
