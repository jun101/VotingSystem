import { expect, type Page } from '@playwright/test';
import { pngBuffer } from './images';

/*
 * Helpers for the browser tests of the elections (slice 05). Part of the acceptance harness.
 * Elections are created through the API with the session of the page, so a test does not click
 * through the form for what it only needs as a starting point.
 */

export type Election = { id: string; title: string; starts_at: string; ends_at: string; status: string };

async function headers(page: Page): Promise<Record<string, string>> {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

/** Creates a draft election as the signed-in person. Dates are UTC; the default time zone is Haiti's. */
export async function createElection(
  page: Page,
  title: string,
  starts = '2026-10-12T12:00:00Z',
  ends = '2026-10-16T19:00:00Z',
  extra: Record<string, unknown> = {},
): Promise<Election> {
  const response = await page.request.post('/api/v1/elections', {
    headers: await headers(page),
    data: { title, starts_at: starts, ends_at: ends, ...extra },
  });
  expect(response.status(), await response.text()).toBe(201);

  return ((await response.json()) as { data: Election }).data;
}

/** Puts a PNG cover on an election through the API. */
export async function putCover(page: Page, id: string, width = 1200, height = 600): Promise<void> {
  const response = await page.request.put(`/api/v1/elections/${id}/cover`, {
    headers: await headers(page),
    multipart: { file: { name: 'cover.png', mimeType: 'image/png', buffer: pngBuffer(width, height) } },
  });
  expect(response.status(), await response.text()).toBe(200);
}

/** The election as the API gives it. */
export async function readElection(page: Page, id: string): Promise<Record<string, unknown>> {
  const response = await page.request.get(`/api/v1/elections/${id}`);
  expect(response.status()).toBe(200);

  return ((await response.json()) as { data: Record<string, unknown> }).data;
}

/** Six drafts over two years, enough to fill two rows of a three-column grid with the "new" tile. */
export async function createSix(page: Page): Promise<void> {
  const dates: [string, string, string][] = [
    ['Conseil des élèves 2026', '2026-10-12T12:00:00Z', '2026-10-16T19:00:00Z'],
    ['Comité des parents 2026', '2026-09-14T12:00:00Z', '2026-09-15T19:00:00Z'],
    ['Délégués de classe 2026', '2026-03-02T12:00:00Z', '2026-03-04T19:00:00Z'],
    ['Délégués de classe 2025', '2025-11-03T12:00:00Z', '2025-11-05T19:00:00Z'],
    ['Conseil des élèves 2025', '2025-10-13T12:00:00Z', '2025-10-17T19:00:00Z'],
    ['Essai de la plateforme', '2025-09-02T12:00:00Z', '2025-09-02T19:00:00Z'],
  ];
  for (const [title, starts, ends] of dates) await createElection(page, title, starts, ends);
}
