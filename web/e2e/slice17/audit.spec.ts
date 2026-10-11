import { expect, test, type Page } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { createElection } from '../support/elections';
import { uniqueEmail } from '../support/mail';
import { createVoter } from '../support/voters';
import { acceptInNewBrowser, invite } from '../support/team';

/*
 * Slice 17 — the audit log (A15). docs/slices/17-audit-log.md. API: docs/api/audit/.
 * The owner does a few things through the API (create and edit an election, change its times, add and delete a voter)
 * and reads them back on /admin/audit, filters them and exports them.
 */

async function headers(page: Page): Promise<Record<string, string>> {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

async function actionsOnPage(page: Page): Promise<string[]> {
  return page
    .locator('[data-testid^="audit-action-"]')
    .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-action') ?? ''));
}

/** An owner who has created an election, edited its title and times and added then removed a voter. */
async function activity(page: Page) {
  await registerAndEnter(page);
  const election = await createElection(page, 'Conseil des élèves', '2030-03-01T12:00:00Z', '2030-03-05T19:00:00Z');
  const base = { headers: await headers(page) };
  const rename = await page.request.patch(`/api/v1/elections/${election.id}`, { ...base, data: { title: 'Conseil des élèves 2030' } });
  expect(rename.status()).toBe(200);
  const times = await page.request.patch(`/api/v1/elections/${election.id}`, { ...base, data: { starts_at: '2030-03-02T12:00:00Z' } });
  expect(times.status()).toBe(200);
  const voter = await createVoter(page, election.id, 'Rose Désir', { group: '4e année' });
  const removed = await page.request.delete(`/api/v1/voters/${voter.id}`, base);
  expect(removed.status()).toBe(204);

  return election;
}

test.describe('the owner reads the log', () => {
  test('shows the actions newest first, with who, which election and when [FR-AUD-01, FR-AUD-02, FR-AUD-03]', async ({ page }) => {
    await activity(page);

    await page.goto('/admin/audit');

    await expect(page.getByTestId('audit-page')).toBeVisible();
    const actions = await actionsOnPage(page);
    expect(actions.slice(0, 5)).toEqual(['voter.deleted', 'voter.created', 'election.times_changed', 'election.updated', 'election.created']);
    await expect(page.getByTestId('audit-actor-1')).toContainText('Marie Joseph');
    await expect(page.getByTestId('audit-election-3')).toContainText('Conseil des élèves 2030');
    await expect(page.getByTestId('audit-time-1')).toBeVisible();
    await expect(page.getByTestId('audit-ip-1')).toBeVisible();
    await expectNoSidewaysScroll(page);
    await expectAccessible(page);
  });

  test('filters by family of actions and by election, and says so when nothing matches [FR-AUD-03]', async ({ page }) => {
    await activity(page);
    await page.goto('/admin/audit');

    await page.getByTestId('audit-filter-action').selectOption('voter.');
    await expect.poll(() => actionsOnPage(page)).toEqual(['voter.deleted', 'voter.created']);

    await page.getByTestId('audit-filter-action').selectOption('credentials.');
    await expect(page.getByTestId('audit-empty')).toBeVisible();

    await page.getByTestId('audit-filter-action').selectOption('election.');
    await page.getByTestId('audit-filter-election').selectOption({ label: 'Conseil des élèves 2030' });
    await expect.poll(async () => (await actionsOnPage(page)).length).toBe(3);
  });

  test('exports the log as CSV and as Excel with the filters on the screen [FR-AUD-03]', async ({ page }) => {
    await activity(page);
    await page.goto('/admin/audit');

    const [csv] = await Promise.all([page.waitForEvent('download'), page.getByTestId('audit-export-csv').click()]);
    expect(csv.suggestedFilename()).toMatch(/^journal-.*\.csv$/);

    await page.getByTestId('audit-filter-action').selectOption('voter.');
    const [xlsx] = await Promise.all([page.waitForEvent('download'), page.getByTestId('audit-export-xlsx').click()]);
    expect(xlsx.suggestedFilename()).toMatch(/^journal-.*\.xlsx$/);
  });

  test('is the owner\'s only: a manager is told it is not for them [FR-AUD-03]', async ({ page, browser }) => {
    await registerAndEnter(page);
    const link = await invite(page, uniqueEmail('manager'));
    const manager = await acceptInNewBrowser(browser, link);

    await manager.goto('/admin/audit');

    await expect(manager.getByTestId('turned-away')).toBeVisible();
    await expect(manager.getByTestId('audit-page')).toHaveCount(0);
    await manager.context().close();
  });
});
