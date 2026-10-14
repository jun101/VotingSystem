import { expect, type Browser, type Page } from '@playwright/test';

/*
 * Slice 15 helpers: vote for a chosen candidate through the voters' API, and close the election as the organiser.
 * Part of the acceptance harness.
 */

/** Casts a vote for the candidate at `index` (0 based, in the order the voter sees) on every ballot, as the voter with this code. */
export async function castVoteFor(browser: Browser, election: string, code: string, index: number): Promise<void> {
  const context = await browser.newContext();
  const request = context.request;

  const session = await request.post(`/api/v1/vote/${election}/session`, { data: { code } });
  expect(session.status(), await session.text()).toBe(201);
  const ballots = (await (await request.get(`/api/v1/vote/${election}/ballots`)).json()) as {
    data: { ballots: { id: string; candidates: { id: string; last_name: string }[] }[] };
  };
  const body = {
    ballots: ballots.data.ballots.map((ballot) => {
      // Sorted by name, so "the first" and "the second" mean the same thing for every voter.
      const sorted = [...ballot.candidates].sort((a, b) => a.last_name.localeCompare(b.last_name));

      return { ballot: ballot.id, candidates: [sorted[Math.min(index, sorted.length - 1)]!.id] };
    }),
  };
  const submit = await request.post(`/api/v1/vote/${election}/submit`, { data: body });
  expect(submit.status(), await submit.text()).toBe(201);
  await context.close();
}

/** Closes the election now, with the page's session (the title is the confirmation). */
export async function closeNow(page: Page, election: string, title: string): Promise<void> {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  const response = await page.request.post(`/api/v1/elections/${election}/close`, {
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' },
    data: { confirmation: title },
  });
  expect(response.status(), await response.text()).toBe(200);
}
