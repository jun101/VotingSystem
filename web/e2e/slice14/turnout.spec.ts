import { expect, test, type Browser, type Page } from '@playwright/test';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { castVote, prepareTurnout, type TurnoutElection } from '../support/turnout';
import { waitUntilOpen } from '../support/voting';

/*
 * Slice 14 — turnout (A12), who has not voted, the voters page flags and the dashboard (A02) with real numbers.
 * docs/slices/14-turnout-and-dashboard.md. API: docs/api/turnout/, docs/api/voters/.
 *
 * One election opened by the scheduler's minute command; six voters (Ada, Bob, Cléo in 4e année, Dan and Eli in
 * 5e année, Fay without a group). Ada and Dan vote first; Bob votes while a page is open.
 */

test.describe.configure({ mode: 'serial' });

let data: TurnoutElection;

test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000);
  data = await prepareTurnout(browser, 25);
});

async function organiser(browser: Browser, path: string): Promise<Page> {
  const context = await browser.newContext({
    storageState: data.storageState,
    locale: 'fr-FR',
    timezoneId: 'America/Port-au-Prince',
  });
  const page = await context.newPage();
  await page.goto(path);

  return page;
}

test('waits for the scheduler to open the election [FR-ELEC-03]', async ({ page }) => {
  test.setTimeout(200_000);

  await waitUntilOpen(page, data.election);
});

test('shows zero turnout and an empty chart before anyone has voted [FR-RES-01]', async ({ browser }) => {
  const page = await organiser(browser, `/admin/elections/${data.election}/turnout`);

  await expect(page.getByTestId('turnout-page')).toBeVisible();
  await expect(page.getByTestId('turnout-eligible')).toContainText('6');
  await expect(page.getByTestId('turnout-voted')).toContainText('0');
  await expect(page.getByTestId('turnout-percentage')).toContainText('0');
});

test('shows the turnout in total, by group and over time once people have voted [FR-RES-01, FR-SEC-04]', async ({ browser }) => {
  await castVote(browser, data.election, data.codes.ada);
  await castVote(browser, data.election, data.codes.dan);
  const page = await organiser(browser, `/admin/elections/${data.election}/turnout`);

  await expect(page.getByTestId('turnout-voted')).toContainText('2');
  await expect(page.getByTestId('turnout-eligible')).toContainText('6');
  await expect(page.getByTestId('turnout-percentage')).toContainText('33');

  await expect(page.getByTestId('turnout-group-1')).toContainText('4e année');
  await expect(page.getByTestId('turnout-group-1')).toContainText('1 / 3');
  await expect(page.getByTestId('turnout-group-2')).toContainText('5e année');
  await expect(page.getByTestId('turnout-group-2')).toContainText('1 / 2');
  await expect(page.getByTestId('turnout-group-3')).toContainText('0 / 1');

  const chart = page.getByTestId('turnout-chart-time');
  await expect(chart).toHaveAttribute('role', 'img');
  expect(await chart.getAttribute('aria-label')).toBeTruthy();
  expect(await chart.evaluate((element) => element.tagName.toLowerCase())).toBe('svg');

  await expectNoSidewaysScroll(page);
  await expectAccessible(page);
});

test('lists who has not voted, with search, group filter and exports, and never what anyone voted [FR-VOT-06, FR-VOT-07]', async ({ browser }) => {
  const page = await organiser(browser, `/admin/elections/${data.election}/turnout`);

  const list = page.getByTestId('nonvoters-list');
  await expect(list).toBeVisible();
  await expect(page.getByTestId('nonvoter-name-1')).toHaveText('Bob Louis');
  await expect(page.getByTestId('nonvoter-name-2')).toHaveText('Cléo Pierre');
  await expect(page.getByTestId('nonvoter-name-3')).toHaveText('Eli Joseph');
  await expect(page.getByTestId('nonvoter-name-4')).toHaveText('Fay Sans-groupe');
  await expect(list).not.toContainText('Ada Joseph');
  await expect(list).not.toContainText('Dan Étienne');

  await page.getByTestId('nonvoters-search').fill('cléo');
  await expect(page.getByTestId('nonvoter-name-1')).toHaveText('Cléo Pierre');
  await expect(page.getByTestId('nonvoter-name-2')).toHaveCount(0);
  await page.getByTestId('nonvoters-search').fill('');

  await page.getByTestId('nonvoters-group').selectOption({ label: '5e année' });
  await expect(page.getByTestId('nonvoter-name-1')).toHaveText('Eli Joseph');
  await expect(page.getByTestId('nonvoter-name-2')).toHaveCount(0);

  const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('nonvoters-export-xlsx').click()]);
  expect(download.suggestedFilename()).toMatch(/^electeurs-participation-du-conseil\.xlsx$/);
  const [csv] = await Promise.all([page.waitForEvent('download'), page.getByTestId('nonvoters-export-csv').click()]);
  expect(csv.suggestedFilename()).toMatch(/\.csv$/);
});

test('flags who has voted on the voters page and filters by it [FR-VOT-06, FR-VOT-07]', async ({ browser }) => {
  const page = await organiser(browser, `/admin/elections/${data.election}/voters`);

  await expect(page.getByTestId('voter-voted-1')).toBeVisible();
  await page.getByTestId('voters-filter-voted').selectOption('yes');

  await expect(page.locator('[data-testid^="voter-voted-"]')).toHaveCount(2);
  await expect(page.getByText('Ada Joseph')).toBeVisible();
  await expect(page.getByText('Dan Étienne')).toBeVisible();
  await expect(page.getByText('Bob Louis')).toHaveCount(0);
});

test('shows the open election with its turnout and what is left to do on the dashboard [FR-NAV-01]', async ({ browser }) => {
  const page = await organiser(browser, '/admin');

  await expect(page.getByTestId('dashboard-election-1')).toContainText(data.title);
  await expect(page.getByTestId('dashboard-turnout-1')).toContainText('33');
  await expect(page.getByTestId('dashboard-todo-1')).toContainText('4');
  await expectNoSidewaysScroll(page);
  await expectAccessible(page);
});

test('refreshes by itself while the election is open [FR-RES-01]', async ({ browser }) => {
  test.setTimeout(90_000);
  const page = await organiser(browser, `/admin/elections/${data.election}/turnout`);
  await expect(page.getByTestId('turnout-voted')).toContainText('2');

  await castVote(browser, data.election, data.codes.bob);

  await expect(page.getByTestId('turnout-voted')).toContainText('3', { timeout: 40_000 });
  await expect(page.getByTestId('nonvoters-list')).not.toContainText('Bob Louis');
});
