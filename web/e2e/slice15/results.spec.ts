import { expect, test, type Browser, type Page } from '@playwright/test';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { closeNow, castVoteFor } from '../support/results';
import { prepareTurnout, type TurnoutElection } from '../support/turnout';
import { waitUntilOpen } from '../support/voting';

/*
 * Slice 15 — results (A13): counts, the tie, the bulletin check, the public note and the second round.
 * docs/slices/15-results.md. API: docs/api/results/.
 *
 * One election opened by the scheduler; six voters. The president ballot has two candidates, "Désir" and "Pierre-Louis"
 * (sorted by name): three voters choose the first, three the second, so the ballot ends in a tie. Then the organiser
 * closes the election.
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

test('says the results are not available while the election is open and shows no count [FR-SEC-03]', async ({ browser }) => {
  const page = await organiser(browser, `/admin/elections/${data.election}/results`);

  await expect(page.getByTestId('results-unavailable')).toBeVisible();
  await expect(page.getByTestId('results-page')).toHaveCount(0);
  await expect(page.locator('[data-testid^="result-candidate-"]')).toHaveCount(0);
});

test('shows the votes, percentages, blank votes, the tie and a passing bulletin check after closing [FR-RES-02, FR-RES-03, FR-SEC-05]', async ({ browser }) => {
  const voters = ['ada', 'bob', 'cleo', 'dan', 'eli', 'fay'] as const;
  for (const [index, key] of voters.entries()) {
    await castVoteFor(browser, data.election, data.codes[key], index < 3 ? 0 : 1);
  }
  const page = await organiser(browser, `/admin/elections/${data.election}`);
  await closeNow(page, data.election, data.title);
  await page.goto(`/admin/elections/${data.election}/results`);

  await expect(page.getByTestId('results-page')).toBeVisible();
  await expect(page.getByTestId('result-ballot-1')).toContainText('Président(e)');
  await expect(page.getByTestId('result-candidate-1-1')).toContainText('3');
  await expect(page.getByTestId('result-candidate-1-1')).toContainText('50');
  await expect(page.getByTestId('result-candidate-1-2')).toContainText('3');
  await expect(page.getByTestId('result-blank-1')).toContainText('0');
  await expect(page.getByTestId('result-tie-1')).toBeVisible();
  await expect(page.locator('[data-testid^="result-winner-1-"]')).toHaveCount(0);
  await expect(page.getByTestId('result-check-1')).toHaveAttribute('data-ok', 'true');

  await expectNoSidewaysScroll(page);
  await expectAccessible(page);
});

test('saves a public note on a ballot and keeps it after a reload [FR-RES-03]', async ({ browser }) => {
  const page = await organiser(browser, `/admin/elections/${data.election}/results`);

  await page.getByTestId('result-note-1').fill('Égalité parfaite : un second tour sera organisé.');
  await page.getByTestId('result-note-save-1').click();
  await expect(page.getByTestId('result-note-save-1')).toBeEnabled();

  await page.reload();
  await expect(page.getByTestId('result-note-1')).toHaveValue('Égalité parfaite : un second tour sera organisé.');
});

test('creates a second round from the tied ballot and goes to the new draft election [FR-RES-09]', async ({ browser }) => {
  const page = await organiser(browser, `/admin/elections/${data.election}/results`);
  await expect(page.getByTestId('result-second-round-1')).toBeVisible();

  await page.getByTestId('result-second-round-1').click();
  await expect(page.getByTestId('second-round-dialog')).toBeVisible();
  await page.getByTestId('second-round-confirm').click();

  const link = page.getByTestId('second-round-link');
  await expect(link).toBeVisible();
  await link.click();
  await expect(page).toHaveURL(/\/admin\/elections\/[0-9a-f-]{36}$/);
  await expect(page.getByTestId('election-summary-title')).toContainText(`Second tour – ${data.title}`);
  await expect(page.getByTestId('election-summary-status')).toHaveAttribute('data-status', 'draft');
});

test('offers no second round once one exists for the ballot [FR-RES-09]', async ({ browser }) => {
  const page = await organiser(browser, `/admin/elections/${data.election}/results`);

  await expect(page.getByTestId('result-tie-1')).toBeVisible();
  await expect(page.getByTestId('result-second-round-1')).toHaveCount(0);
});
