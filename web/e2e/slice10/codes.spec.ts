import { readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { createElection } from '../support/elections';
import { createVoter } from '../support/voters';
import { CODE, readCredentials, readFiles } from '../support/credentials';

/*
 * Slice 10 — access codes and printed slips: A11. docs/slices/10-codes-and-slips.md. API: docs/api/credentials/.
 * Mockup: Codes.dc.html.
 */

/** An election with two groups (3 and 2 voters) and one voter with no group, on its codes page. */
async function openCodes(page: Page, title = 'Codes') {
  await registerAndEnter(page);
  const election = await createElection(page, title);
  for (const name of ['Rose-Marie Désir', 'Jean-Baptiste Pierre', 'Nadège Louis']) await createVoter(page, election.id, name, { group: '4e année' });
  for (const name of ['Wideline Joseph', 'Kervens Étienne']) await createVoter(page, election.id, name, { group: '5e année' });
  await createVoter(page, election.id, 'Marc Sans-groupe');
  await page.goto(`/admin/elections/${election.id}/codes`);
  await expect(page.getByTestId('codes-page')).toBeVisible();
  const state = await readCredentials(page, election.id);
  const key = (name: string | null) => state.groups.find((line) => (line.group?.name ?? null) === name)!.group?.id ?? 'none';

  return { election, state, g4: key('4e année'), g5: key('5e année'), none: key(null) };
}

test.describe('the state (A11)', () => {
  test('shows the totals and one card per group, with nothing generated yet [FR-CRED-01]', async ({ page }) => {
    const { g4, g5, none } = await openCodes(page);

    await expect(page.getByTestId('codes-total-voters')).toContainText('6');
    await expect(page.getByTestId('codes-total-with-code')).toContainText('0');
    await expect(page.getByTestId('codes-total-without-code')).toContainText('6');
    await expect(page.getByTestId(`codes-group-${g4}`)).toContainText('4e année');
    await expect(page.getByTestId(`codes-group-${g4}-count`)).toContainText('0');
    await expect(page.getByTestId(`codes-group-${g5}`)).toContainText('5e année');
    await expect(page.getByTestId(`codes-group-${none}`)).toBeVisible();
    await expect(page.getByTestId(`codes-group-${g4}-download`)).toHaveCount(0);
  });

  test('is reached from the fourth step of the election page, one click [FR-CRED-04]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Chemin');
    await page.goto(`/admin/elections/${election.id}`);

    await expect(page.getByTestId('election-step-4')).toHaveAttribute('data-state', 'ready');
    await page.getByTestId('election-step-4').click();

    await expect(page).toHaveURL(`/admin/elections/${election.id}/codes`);
    await expect(page.getByTestId('codes-page')).toBeVisible();
  });
});

test.describe('generating and downloading', () => {
  test('generates the codes of one group and downloads its PDF [FR-CRED-01, FR-CRED-04, FR-CRED-05]', async ({ page }) => {
    const { election, g4, g5 } = await openCodes(page);

    await page.getByTestId(`codes-group-${g4}-generate`).click();

    await expect(page.getByTestId(`codes-group-${g4}-download`)).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId(`codes-group-${g4}-count`)).toContainText('3');
    await expect(page.getByTestId(`codes-group-${g5}-count`)).toContainText('0');
    await expect(page.getByTestId('codes-total-with-code')).toContainText('3');
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId(`codes-group-${g4}-download`).click()]);
    expect(download.suggestedFilename()).toBe('coupons-4e-annee.pdf');
    expect(await download.failure()).toBeNull();
    expect(readFileSync((await download.path())!).subarray(0, 5).toString()).toBe('%PDF-');
    const state = await readCredentials(page, election.id);
    expect(state.with_code).toBe(3);
    expect((await readFiles(page, election.id)).filter((file) => file.status === 'ready')).toHaveLength(1);
  });

  test('generates every missing code at once and every group gets its PDF [FR-CRED-01, FR-CRED-04]', async ({ page }) => {
    const { election, g4, g5, none } = await openCodes(page);

    await page.getByTestId('codes-generate-all').click();

    for (const key of [g4, g5, none]) {
      await expect(page.getByTestId(`codes-group-${key}-download`)).toBeVisible({ timeout: 30_000 });
    }
    await expect(page.getByTestId('codes-total-with-code')).toContainText('6');
    await expect(page.getByTestId('codes-total-without-code')).toContainText('0');
    expect((await readFiles(page, election.id)).map((file) => file.status)).toEqual(['ready', 'ready', 'ready']);
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId(`codes-group-${none}-download`).click()]);
    expect(download.suggestedFilename()).toBe('coupons-sans-groupe.pdf');
  });

  test('does not offer to generate again when every voter has a code [FR-CRED-01]', async ({ page }) => {
    const { g4, g5, none } = await openCodes(page);
    await page.getByTestId('codes-generate-all').click();
    await expect(page.getByTestId(`codes-group-${g4}-download`)).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId(`codes-group-${g5}-download`)).toBeVisible();
    await expect(page.getByTestId(`codes-group-${none}-download`)).toBeVisible();

    await expect(page.getByTestId('codes-generate-all')).toBeDisabled();
    await expect(page.getByTestId(`codes-group-${g4}-generate`)).toBeDisabled();
  });
});

test.describe('a lost slip', () => {
  test('gives a new code once, with a one-slip PDF, and replaces the old one [FR-CRED-03]', async ({ page }) => {
    const { election, g4 } = await openCodes(page);
    await page.getByTestId(`codes-group-${g4}-generate`).click();
    await expect(page.getByTestId(`codes-group-${g4}-download`)).toBeVisible({ timeout: 30_000 });

    await page.getByTestId('codes-lost').click();
    await page.getByTestId('lost-search').fill('Rose');
    await page.getByTestId('lost-voter-1').click();
    await page.getByTestId('lost-confirm').click();

    const code = (await page.getByTestId('lost-code').textContent())!.trim();
    expect(code).toMatch(CODE);
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('lost-download').click()]);
    expect(readFileSync((await download.path())!).subarray(0, 5).toString()).toBe('%PDF-');
    await page.getByTestId('lost-close').click();
    await expect(page.getByTestId('lost-code')).toHaveCount(0);
    await page.reload();
    expect(await page.content()).not.toContain(code);
    expect((await readFiles(page, election.id)).some((file) => file.voter_count === 1)).toBe(true);
  });

  test('gives a first code to a voter who had none [FR-CRED-01]', async ({ page }) => {
    const { election } = await openCodes(page);

    await page.getByTestId('codes-lost').click();
    await page.getByTestId('lost-search').fill('Marc');
    await page.getByTestId('lost-voter-1').click();
    await page.getByTestId('lost-confirm').click();

    await expect(page.getByTestId('lost-code')).toHaveText(CODE);
    expect((await readCredentials(page, election.id)).with_code).toBe(1);
  });
});

test.describe('quality', () => {
  test('is accessible and never scrolls sideways on both screens [NFR-UX-02, NFR-UX-03]', async ({ page }) => {
    const { g4 } = await openCodes(page);
    await expectAccessible(page);
    await expectNoSidewaysScroll(page);

    await page.getByTestId(`codes-group-${g4}-generate`).click();
    await expect(page.getByTestId(`codes-group-${g4}-download`)).toBeVisible({ timeout: 30_000 });
    await page.getByTestId('codes-lost').click();
    await expectAccessible(page);
    await expectNoSidewaysScroll(page);
  });
});
