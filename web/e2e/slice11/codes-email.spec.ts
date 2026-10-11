import { readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';
import { registerAndEnter, verifyEmail } from '../support/admin';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { createElection } from '../support/elections';
import { createVoter, readVoters } from '../support/voters';
import { CODE, readCredentials } from '../support/credentials';
import { uniqueEmail, waitForMails } from '../support/mail';

/*
 * Slice 11 — sending the codes by email and the self-distribution file, on A11 (and the status on the voters page).
 * docs/slices/11-email-and-file.md. API: docs/api/credentials/. Test ids are in the brief.
 */

/** An election with Rose and Jean (an address each, group "4e année"), Nadège (no address) on the codes page. */
async function openCodes(page: Page) {
  const me = await registerAndEnter(page);
  await verifyEmail(page, me.email);
  const election = await createElection(page, 'Courriels');
  const rose = uniqueEmail('rose');
  const jean = uniqueEmail('jean');
  await createVoter(page, election.id, 'Rose-Marie Désir', { group: '4e année', email: rose });
  await createVoter(page, election.id, 'Jean-Baptiste Pierre', { group: '4e année', email: jean });
  await createVoter(page, election.id, 'Nadège Louis', { group: '4e année' });
  await page.goto(`/admin/elections/${election.id}/codes`);
  await expect(page.getByTestId('codes-page')).toBeVisible();

  return { election, rose, jean };
}

test.describe('the send dialog', () => {
  test('shows the numbers before anything is sent and lists the voters left for print [FR-CRED-07]', async ({ page }) => {
    const { election } = await openCodes(page);

    await page.getByTestId('codes-send').click();
    await page.getByTestId('send-email').check();

    await expect(page.getByTestId('send-count-generate')).toContainText('3');
    await expect(page.getByTestId('send-count-email')).toContainText('2');
    await expect(page.getByTestId('send-count-no-email')).toContainText('1');
    await expect(page.getByTestId('send-no-email-list')).toContainText('Nadège Louis');
    expect((await readCredentials(page, election.id)).with_code).toBe(0);
  });

  test('sends the emails after the confirmation and shows the status per group [FR-CRED-04, FR-CRED-07]', async ({ page }) => {
    const { election, rose } = await openCodes(page);

    await page.getByTestId('codes-send').click();
    await page.getByTestId('send-email').check();
    await expect(page.getByTestId('send-count-email')).toContainText('2');
    await page.getByTestId('send-confirm').click();

    await expect(page.getByTestId('codes-run-result')).toBeVisible({ timeout: 60_000 });
    await expect(page.getByTestId('codes-run-emails')).toContainText('2');
    const [mail] = await waitForMails(rose, 1);
    const code = /[A-HJKMNP-Z2-9]{5}-[A-HJKMNP-Z2-9]{5}/.exec(mail!.text)?.[0] ?? '';
    expect(code).toMatch(CODE);
    expect(mail!.text).toContain(`/vote/${election.id}#${code}`);
    await expect(page.getByTestId('codes-total-email-sent')).toContainText('2', { timeout: 30_000 });
    await expect(page.getByTestId('codes-total-email-failed')).toContainText('0');
  });

  test('also writes the Excel file and offers it for download [FR-CRED-04, FR-CRED-05]', async ({ page }) => {
    await openCodes(page);

    await page.getByTestId('codes-send').click();
    await page.getByTestId('send-file').selectOption('xlsx');
    await page.getByTestId('send-confirm').click();

    await expect(page.getByTestId('codes-file-1')).toBeVisible({ timeout: 60_000 });
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('codes-file-1').click()]);
    expect(download.suggestedFilename()).toBe('codes-tous.xlsx');
    expect(readFileSync((await download.path())!).subarray(0, 2).toString()).toBe('PK');
  });
});

test.describe('the status on the voters page', () => {
  test('shows who has a code and the status of the email, and filters by them [FR-VOT-06]', async ({ page }) => {
    const { election } = await openCodes(page);
    await page.getByTestId('codes-send').click();
    await page.getByTestId('send-email').check();
    await page.getByTestId('send-confirm').click();
    await expect(page.getByTestId('codes-run-result')).toBeVisible({ timeout: 60_000 });

    await page.goto(`/admin/elections/${election.id}/voters`);
    await page.getByTestId('voters-filter-credential').selectOption('none');

    await expect.poll(async () => (await readVoters(page, election.id, '&credential=none')).length).toBe(0);
    await page.getByTestId('voters-filter-credential').selectOption('issued');
    await expect(page.locator('[data-testid^="voter-name-"]')).toHaveCount(3);
    await page.getByTestId('voters-filter-email').selectOption('sent');
    await expect(page.locator('[data-testid^="voter-name-"]')).toHaveCount(2);
  });
});

test.describe('a lost slip, by email', () => {
  test('emails the new code to a voter who has an address [FR-CRED-04]', async ({ page }) => {
    const { election, rose } = await openCodes(page);

    await page.getByTestId('codes-lost').click();
    await page.getByTestId('lost-search').fill('Rose');
    await page.getByTestId('lost-voter-1').click();
    await page.getByTestId('lost-email').check();
    await page.getByTestId('lost-confirm').click();

    const code = (await page.getByTestId('lost-code').textContent())!.trim();
    const [mail] = await waitForMails(rose, 1);
    expect(mail!.text).toContain(code);
    expect(mail!.text).toContain(`/vote/${election.id}#${code}`);
  });

  test('offers no email choice for a voter with no address [FR-CRED-04]', async ({ page }) => {
    await openCodes(page);

    await page.getByTestId('codes-lost').click();
    await page.getByTestId('lost-search').fill('Nadège');
    await page.getByTestId('lost-voter-1').click();

    await expect(page.getByTestId('lost-email')).toHaveCount(0);
  });
});

test.describe('quality', () => {
  test('is accessible and never scrolls sideways on both screens [NFR-UX-02, NFR-UX-03]', async ({ page }) => {
    await openCodes(page);
    await page.getByTestId('codes-send').click();
    await page.getByTestId('send-email').check();
    await expect(page.getByTestId('send-count-email')).toContainText('2');

    await expectAccessible(page);
    await expectNoSidewaysScroll(page);
  });
});
