import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { phaseOf, prepareElection, waitUntilOpen, type Voting } from '../support/voting';

/*
 * Slice 12 — the voting flow, M1 to M5. docs/slices/12-voting-flow.md. API: docs/api/vote/.
 * The election is scheduled by the test and opened by the scheduler's minute command, so the first test sees the
 * countdown and the others wait for it.
 */

test.describe.configure({ mode: 'serial' });

let voting: Voting;

test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000);
  voting = await prepareElection(browser, 25, 6);
});

async function enter(page: Page, code: string) {
  await page.goto(`/vote/${voting.election}`);
  await page.getByTestId('vote-code-input').fill(code);
  await page.getByTestId('vote-code-submit').click();
}

test('shows the countdown before the start and no code entry [FR-VOTE-02]', async ({ page }) => {
  test.skip((await phaseOf(page, voting.election)) !== 'before', 'the scheduler already opened the election');

  await page.goto(`/vote/${voting.election}`);

  await expect(page.getByTestId('vote-election-title')).toHaveText(voting.title);
  await expect(page.getByTestId('vote-countdown')).toBeVisible();
  await expect(page.getByTestId('vote-code-input')).toHaveCount(0);
});

test('waits for the scheduler to open the election [FR-ELEC-03]', async ({ page }) => {
  test.setTimeout(200_000);

  await waitUntilOpen(page, voting.election);
});

test('lets a person type the code, shows who they are and the ballots they vote on [FR-VOTE-01, FR-VOTE-03]', async ({ page }) => {
  await enter(page, voting.codes.wideline.toLowerCase().replace('-', ''));

  await expect(page).toHaveURL(`/vote/${voting.election}/identity`);
  await expect(page.getByTestId('vote-identity-name')).toHaveText('Wideline Joseph');
  await expect(page.getByTestId('vote-identity-group')).toContainText('4e année');
  await expect(page.locator('[data-testid^="vote-identity-ballot-"]')).toHaveCount(2);
});

test('says the code is not valid and keeps the person on the page [FR-VOTE-01]', async ({ page }) => {
  await enter(page, 'ZZZZZ-ZZZZZ');

  await expect(page.getByTestId('vote-code-error')).toBeVisible();
  await expect(page).toHaveURL(`/vote/${voting.election}`);
});

test('goes back to code entry when the person says it is not them, and ends the session [FR-VOTE-03]', async ({ page }) => {
  await enter(page, voting.codes.paul);
  await expect(page.getByTestId('vote-identity-name')).toHaveText('Paul Étienne');

  await page.getByTestId('vote-identity-no').click();

  await expect(page).toHaveURL(`/vote/${voting.election}`);
  await page.goto(`/vote/${voting.election}/identity`);
  await expect(page).toHaveURL(`/vote/${voting.election}`);
});

test('reads the code from the link and takes it out of the address bar [FR-CRED-06, FR-VOTE-01]', async ({ page }) => {
  await page.goto(`/vote/${voting.election}#${voting.codes.marc}`);

  await expect(page).toHaveURL(`/vote/${voting.election}/identity`);
  expect(page.url()).not.toContain('#');
  expect(page.url()).not.toContain(voting.codes.marc);
  await expect(page.getByTestId('vote-identity-name')).toHaveText('Marc Sans-groupe');
  await expect(page.locator('[data-testid^="vote-identity-ballot-"]')).toHaveCount(1);
});

test('votes through every ballot, reviews, confirms once, and cannot vote again [FR-VOTE-03 to 07]', async ({ page }) => {
  await page.goto(`/vote/${voting.election}#${voting.codes.wideline}`);
  await page.getByTestId('vote-identity-yes').click();

  await expect(page.getByTestId('vote-ballot-title')).toHaveText('Président(e)');
  await expect(page.getByTestId('vote-ballot-progress')).toContainText('1');
  await page.getByTestId('vote-candidate-1').click();
  await expect(page.getByTestId('vote-candidate-1')).toHaveAttribute('aria-pressed', 'true');
  await page.getByTestId('vote-ballot-next').click();

  await expect(page.getByTestId('vote-ballot-title')).toHaveText('Délégué(e) de classe');
  await expect(page.getByTestId('vote-blank')).toHaveCount(0);
  await page.getByTestId('vote-candidate-2').click();
  await page.getByTestId('vote-ballot-next').click();

  await expect(page.getByTestId('vote-review')).toBeVisible();
  await expect(page.getByTestId('vote-review-ballot-1')).toContainText('Pierre-Louis');
  await expect(page.getByTestId('vote-review-ballot-2')).toContainText('Augustin');
  await page.getByTestId('vote-confirm').click();

  await expect(page.getByTestId('vote-done')).toBeVisible();
  await expect(page.getByTestId('vote-done-time')).not.toBeEmpty();
  await expect(page.getByTestId('vote-done-countdown')).toBeVisible();
  expect(await page.content()).not.toContain('Pierre-Louis');
  await page.getByTestId('vote-done-next').click();
  await expect(page).toHaveURL(`/vote/${voting.election}`);

  await enter(page, voting.codes.wideline);
  await expect(page.getByTestId('vote-code-error')).toBeVisible();
});

test('allows a blank vote where the ballot allows it and refuses it where it does not [FR-VOTE-04]', async ({ page }) => {
  await page.goto(`/vote/${voting.election}#${voting.codes.extra[0]}`);
  await page.getByTestId('vote-identity-yes').click();

  await page.getByTestId('vote-blank').click();
  await expect(page.getByTestId('vote-blank')).toHaveAttribute('aria-pressed', 'true');
  await page.getByTestId('vote-ballot-next').click();
  await expect(page.getByTestId('vote-ballot-next')).toBeDisabled();
  await page.getByTestId('vote-candidate-1').click();
  await page.getByTestId('vote-ballot-next').click();
  await page.getByTestId('vote-confirm').click();

  await expect(page.getByTestId('vote-done')).toBeVisible();
});

test('goes back and changes a choice from the review before confirming [FR-VOTE-03]', async ({ page }) => {
  await page.goto(`/vote/${voting.election}#${voting.codes.extra[1]}`);
  await page.getByTestId('vote-identity-yes').click();
  await page.getByTestId('vote-candidate-1').click();
  await page.getByTestId('vote-ballot-next').click();
  await page.getByTestId('vote-candidate-1').click();
  await page.getByTestId('vote-ballot-next').click();

  await page.getByTestId('vote-review-edit-1').click();
  await page.getByTestId('vote-candidate-2').click();
  await page.getByTestId('vote-ballot-next').click();
  await page.getByTestId('vote-ballot-next').click();

  await expect(page.getByTestId('vote-review-ballot-1')).toContainText('Désir');
});

test('switches between French and English on the voting screens [FR-VOTE-01]', async ({ page }) => {
  await page.goto(`/vote/${voting.election}`);

  await page.getByTestId('vote-language-en').click();
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await page.getByTestId('vote-language-fr').click();
  await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
});

test.describe('quality', () => {
  test('is accessible and never scrolls sideways on code entry, identity and a ballot [NFR-UX-02, NFR-UX-03]', async ({ page }) => {
    await page.goto(`/vote/${voting.election}`);
    await expectAccessible(page);
    await expectNoSidewaysScroll(page);

    await enter(page, voting.codes.extra[2]);
    await expect(page.getByTestId('vote-identity')).toBeVisible();
    await expectAccessible(page);
    await expectNoSidewaysScroll(page);

    await page.getByTestId('vote-identity-yes').click();
    await expect(page.getByTestId('vote-ballot')).toBeVisible();
    await expectAccessible(page);
    await expectNoSidewaysScroll(page);
  });
});
