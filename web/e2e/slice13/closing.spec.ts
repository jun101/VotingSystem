import { expect, test, type Page } from '@playwright/test';
import { prepareClosing, shiftLocal, type Closing } from '../support/closing';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { readElection } from '../support/elections';
import { waitUntilOpen } from '../support/voting';

/*
 * Slice 13 — closing early and extending, on the summary page (A05), and what a voter in session sees.
 * docs/slices/13-opening-and-closing.md. API: docs/api/elections/POST-elections-{election}-close.md and
 * POST-elections-{election}-extend.md.
 *
 * One election, opened by the scheduler's minute command, used by the tests in order: look, extend, refuse a bad end,
 * try closing and cancel, close while a voter is in session.
 */

test.describe.configure({ mode: 'serial' });

let closing: Closing;

test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000);
  closing = await prepareClosing(browser, 25);
});

async function organiser(browser: import('@playwright/test').Browser): Promise<Page> {
  const context = await browser.newContext({
    storageState: closing.storageState,
    locale: 'fr-FR',
    timezoneId: 'America/Port-au-Prince',
  });
  const page = await context.newPage();
  await page.goto(`/admin/elections/${closing.election}`);

  return page;
}

test('waits for the scheduler to open the election [FR-ELEC-03]', async ({ page }) => {
  test.setTimeout(200_000);

  await waitUntilOpen(page, closing.election);
});

test('shows the open card with the end, a countdown and the two actions [FR-ELEC-05]', async ({ browser }) => {
  const page = await organiser(browser);

  await expect(page.getByTestId('election-summary-status')).toHaveAttribute('data-status', 'open');
  await expect(page.getByTestId('election-open-card')).toBeVisible();
  await expect(page.getByTestId('election-open-ends')).toBeVisible();
  await expect(page.getByTestId('election-open-countdown')).toContainText(/\d/);
  await expect(page.getByTestId('election-close-button')).toBeVisible();
  await expect(page.getByTestId('election-extend-button')).toBeVisible();
  await expect(page.getByTestId('election-unschedule-button')).toHaveCount(0);
  await expectNoSidewaysScroll(page);
  await expectAccessible(page);
});

test('extends the end by a day through the dialog and the election stays open [FR-ELEC-05]', async ({ browser }) => {
  const page = await organiser(browser);
  const before = (await readElection(page, closing.election)).ends_at as string;

  await page.getByTestId('election-extend-button').click();
  await expect(page.getByTestId('extend-dialog')).toBeVisible();
  const field = page.getByTestId('extend-date');
  await field.fill(shiftLocal(await field.inputValue(), 1));
  await page.getByTestId('extend-confirm').click();

  await expect(page.getByTestId('extend-dialog')).toHaveCount(0);
  const after = await readElection(page, closing.election);
  expect(after.status).toBe('open');
  expect(Date.parse(after.ends_at as string) - Date.parse(before)).toBe(24 * 3600 * 1000);
});

test('says why an end that is not later is refused and keeps the dialog open [FR-ELEC-05]', async ({ browser }) => {
  const page = await organiser(browser);
  const before = (await readElection(page, closing.election)).ends_at as string;

  await page.getByTestId('election-extend-button').click();
  const field = page.getByTestId('extend-date');
  await field.fill(shiftLocal(await field.inputValue(), -1));
  await page.getByTestId('extend-confirm').click();

  await expect(page.getByTestId('extend-error')).toBeVisible();
  await expect(page.getByTestId('extend-dialog')).toBeVisible();
  expect((await readElection(page, closing.election)).ends_at).toBe(before);
  await expectAccessible(page);
});

test('keeps the close button disabled until the title is typed, and cancel changes nothing [FR-ELEC-05]', async ({ browser }) => {
  const page = await organiser(browser);

  await page.getByTestId('election-close-button').click();
  await expect(page.getByTestId('close-dialog')).toBeVisible();
  await expect(page.getByTestId('close-confirm')).toBeDisabled();

  await page.getByTestId('close-confirmation').fill('Une autre élection');
  await expect(page.getByTestId('close-confirm')).toBeDisabled();

  await page.getByTestId('close-cancel').click();
  await expect(page.getByTestId('close-dialog')).toHaveCount(0);
  expect((await readElection(page, closing.election)).status).toBe('open');
});

test('closes early with the title typed and tells a voter in session [FR-ELEC-05, FR-VOTE-02]', async ({ browser }) => {
  const admin = await organiser(browser);

  // A voter has entered their code and is on the identity screen.
  const voterContext = await browser.newContext({ locale: 'fr-FR', timezoneId: 'America/Port-au-Prince' });
  const voter = await voterContext.newPage();
  await voter.goto(`/vote/${closing.election}`);
  await voter.getByTestId('vote-code-input').fill(closing.code);
  await voter.getByTestId('vote-code-submit').click();
  await expect(voter.getByTestId('vote-identity-name')).toBeVisible();

  await admin.getByTestId('election-close-button').click();
  await admin.getByTestId('close-confirmation').fill(`  ${closing.title.toUpperCase()}  `);
  await expect(admin.getByTestId('close-confirm')).toBeEnabled();
  await admin.getByTestId('close-confirm').click();

  await expect(admin.getByTestId('election-summary-status')).toHaveAttribute('data-status', 'closed');
  await expect(admin.getByTestId('election-closed-note')).toBeVisible();
  await expect(admin.getByTestId('election-open-card')).toHaveCount(0);
  await expect(admin.getByTestId('election-close-button')).toHaveCount(0);
  await expect(admin.getByTestId('election-extend-button')).toHaveCount(0);

  await voter.getByTestId('vote-identity-yes').click();
  await expect(voter.getByTestId('vote-closed')).toBeVisible();
  await voterContext.close();
});

test('stays closed after a reload, with no way to reopen it, and the voting page says closed [FR-ELEC-03]', async ({ browser, page }) => {
  const admin = await organiser(browser);

  await expect(admin.getByTestId('election-summary-status')).toHaveAttribute('data-status', 'closed');
  await expect(admin.getByTestId('election-open-card')).toHaveCount(0);
  await expectNoSidewaysScroll(admin);
  await expectAccessible(admin);

  await page.goto(`/vote/${closing.election}`);
  await expect(page.getByTestId('vote-closed')).toBeVisible();
  await expect(page.getByTestId('vote-code-input')).toHaveCount(0);
});
