import { expect, test } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { createBallot } from '../support/ballots';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { createElection } from '../support/elections';
import { createParty, readParties, rowNames } from '../support/parties';

/*
 * Slice 06b — the parties card of the ballots page and the party modal (screen A06).
 * docs/slices/06b-parties.md. API: docs/api/parties/. Design: docs/design/frontend.md 1.5 items 9 to 12,
 * mockups material-ballots.html and material-party-modal.html.
 */

async function open(page: import('@playwright/test').Page, title = 'Partis') {
  await registerAndEnter(page);
  const election = await createElection(page, title);
  await page.goto(`/admin/elections/${election.id}/ballots`);
  return election;
}

test.describe('an election with no party', () => {
  test('shows the parties card with an invitation and a count of zero [FR-CAND-01]', async ({ page }) => {
    await open(page);

    await expect(page.getByTestId('parties-card')).toBeVisible();
    await expect(page.getByTestId('parties-empty')).toBeVisible();
    await expect(page.getByTestId('parties-count')).toContainText('0');
    await expect(page.getByTestId('party-new')).toBeVisible();
    await expect(page.locator('[data-testid^="party-row-"]')).toHaveCount(0);
  });
});

test.describe('registering a party in a modal', () => {
  test('opens from the card button, saves, closes and the card updates without leaving the page [FR-CAND-01]', async ({ page }) => {
    const election = await open(page);
    const url = page.url();

    await page.getByTestId('party-new').click();
    await expect(page.getByTestId('party-modal')).toBeVisible();
    await expect(page.getByTestId('party-modal')).toHaveAttribute('role', 'dialog');
    await page.getByTestId('party-form-name').fill('Avenir Étudiant');
    await page.getByTestId('party-form-acronym').fill('AE');
    await page.getByTestId('party-form-colour-C2410C').check();
    await page.getByTestId('party-form-save').click();

    await expect(page.getByTestId('party-modal')).toHaveCount(0);
    expect(page.url()).toBe(url);
    await expect(page.getByTestId('party-name-1')).toHaveText('Avenir Étudiant');
    await expect(page.getByTestId('party-acronym-1')).toHaveText('AE');
    await expect(page.getByTestId('parties-count')).toContainText('1');
    await expect(page.getByTestId('parties-empty')).toHaveCount(0);
    expect(await readParties(page, election.id)).toMatchObject([{ name: 'Avenir Étudiant', acronym: 'AE', colour: '#C2410C' }]);
  });

  test('opens from the header button too, with the first colour chosen by default [FR-CAND-01]', async ({ page }) => {
    const election = await open(page);

    await page.getByTestId('parties-add').click();
    await expect(page.getByTestId('party-form-colour-5468D4')).toBeChecked();
    await page.getByTestId('party-form-name').fill('Ensemble');
    await page.getByTestId('party-form-save').click();

    await expect(page.getByTestId('party-name-1')).toHaveText('Ensemble');
    expect((await readParties(page, election.id))[0].colour).toBe('#5468D4');
  });

  test('"save and add another" keeps the modal open, clears the form and focuses the name [FR-CAND-01]', async ({ page }) => {
    const election = await open(page);

    await page.getByTestId('party-new').click();
    await page.getByTestId('party-form-name').fill('Premier');
    await page.getByTestId('party-form-save-another').click();

    await expect(page.getByTestId('party-modal')).toBeVisible();
    await expect(page.getByTestId('party-form-name')).toHaveValue('');
    await expect(page.getByTestId('party-form-name')).toBeFocused();
    await page.getByTestId('party-form-name').fill('Second');
    await page.getByTestId('party-form-save').click();

    await expect(page.getByTestId('party-modal')).toHaveCount(0);
    await expect.poll(() => rowNames(page)).toEqual(['Premier', 'Second']);
    expect((await readParties(page, election.id)).map((p) => p.name)).toEqual(['Premier', 'Second']);
  });

  test('refuses an empty name and a name already used, beside the field, and stays open [FR-CAND-01]', async ({ page }) => {
    const election = await open(page);
    await createParty(page, election.id, 'Ensemble');
    await page.reload();

    await page.getByTestId('party-new').click();
    await page.getByTestId('party-form-save').click();
    await expect(page.getByTestId('party-form-error-name')).toBeVisible();

    await page.getByTestId('party-form-name').fill('  ensemble ');
    await page.getByTestId('party-form-save').click();
    await expect(page.getByTestId('party-form-error-name')).toBeVisible();
    await expect(page.getByTestId('party-modal')).toBeVisible();
    expect(await readParties(page, election.id)).toHaveLength(1);
  });

  test('closes with Escape, with the close button or with cancel, and gives the focus back [FR-CAND-01]', async ({ page }) => {
    await open(page);
    const opener = page.getByTestId('party-new');

    await opener.click();
    await page.getByTestId('party-form-name').fill('Abandonné');
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('party-modal')).toHaveCount(0);
    await expect(opener).toBeFocused();

    await opener.click();
    await page.getByTestId('party-modal-close').click();
    await expect(page.getByTestId('party-modal')).toHaveCount(0);

    await opener.click();
    await page.getByTestId('party-form-cancel').click();
    await expect(page.getByTestId('party-modal')).toHaveCount(0);
    await expect(page.locator('[data-testid^="party-row-"]')).toHaveCount(0);
  });

  test('keeps the keyboard inside the modal and the page behind still [NFR-UX-03]', async ({ page }) => {
    await open(page);
    await page.getByTestId('party-new').click();

    for (let i = 0; i < 25; i += 1) {
      await page.keyboard.press('Tab');
      const inside = await page.evaluate(() => document.activeElement?.closest('[role="dialog"]') !== null);
      expect(inside, `focus left the modal at Tab ${i + 1}`).toBe(true);
    }
    const overflow = await page.evaluate(() => getComputedStyle(document.body).overflow);
    expect(overflow).toBe('hidden');
  });
});

test.describe('editing and deleting', () => {
  test('edits a party in the same modal, filled with its values [FR-CAND-01]', async ({ page }) => {
    const election = await open(page);
    await createParty(page, election.id, 'Ensemble', '#C2410C', 'EN');
    await page.reload();

    await page.getByTestId('party-edit-1').click();
    await expect(page.getByTestId('party-form-name')).toHaveValue('Ensemble');
    await expect(page.getByTestId('party-form-acronym')).toHaveValue('EN');
    await expect(page.getByTestId('party-form-colour-C2410C')).toBeChecked();
    await page.getByTestId('party-form-name').fill('Ensemble pour l’école');
    await page.getByTestId('party-form-colour-0F766E').check();
    await page.getByTestId('party-form-save').click();

    await expect(page.getByTestId('party-name-1')).toHaveText('Ensemble pour l’école');
    expect(await readParties(page, election.id)).toMatchObject([{ name: 'Ensemble pour l’école', colour: '#0F766E' }]);
  });

  test('deletes after a confirmation that names the party [FR-CAND-01]', async ({ page }) => {
    const election = await open(page);
    await createParty(page, election.id, 'Alpha');
    await createParty(page, election.id, 'Beta');
    await page.reload();

    await page.getByTestId('party-delete-1').click();
    await expect(page.getByTestId('party-delete-dialog')).toContainText('Alpha');
    await page.getByTestId('party-delete-cancel').click();
    await expect.poll(() => rowNames(page)).toEqual(['Alpha', 'Beta']);

    await page.getByTestId('party-delete-1').click();
    await page.getByTestId('party-delete-confirm').click();

    await expect.poll(() => rowNames(page)).toEqual(['Beta']);
    await expect(page.getByTestId('parties-count')).toContainText('1');
    expect((await readParties(page, election.id)).map((p) => p.name)).toEqual(['Beta']);
  });
});

test.describe('the election duplicate', () => {
  test('copies the parties together with the ballots [FR-ELEC-06]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Source');
    await createBallot(page, election.id, 'Président(e)');
    await createParty(page, election.id, 'Avenir Étudiant', '#5468D4', 'AE');
    await page.goto('/admin/elections');

    await page.getByTestId('election-duplicate-1').click();

    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(2);
    const list = await page.request.get('/api/v1/elections');
    const copy = ((await list.json()) as { data: { id: string }[] }).data.find((e) => e.id !== election.id);
    const parties = await readParties(page, copy!.id);
    expect(parties).toMatchObject([{ name: 'Avenir Étudiant', acronym: 'AE', colour: '#5468D4' }]);
    expect(parties[0].id).not.toBe((await readParties(page, election.id))[0].id);
  });
});

test.describe('the modal on every screen (frontend.md 1.5 items 11 and 12)', () => {
  test('is centred on a 1920 by 1080 screen, within the screen, accessible [NFR-UX-03]', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await open(page);

    await page.getByTestId('party-new').click();

    const box = (await page.getByTestId('party-modal').boundingBox())!;
    expect(box.width).toBeLessThanOrEqual(560);
    expect(box.x).toBeGreaterThan(400);
    expect(box.y + box.height).toBeLessThanOrEqual(1080);
    await expectAccessible(page);
  });

  test('is a bottom sheet on a phone, full width, accessible, with no sideways scroll [NFR-UX-02, NFR-UX-03]', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await open(page);

    await page.getByTestId('party-new').click();

    const box = (await page.getByTestId('party-modal').boundingBox())!;
    expect(Math.round(box.width)).toBe(360);
    expect(Math.round(box.y + box.height)).toBe(780);
    expect(box.height).toBeLessThanOrEqual(780 * 0.92 + 1);
    await expectNoSidewaysScroll(page);
    await expectAccessible(page);
  });

  test('the parties card shows its rows, count and actions on a phone and beside the zone at 1920 [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Cartes');
    await createParty(page, election.id, 'Alpha', '#5468D4', 'AL');
    await createParty(page, election.id, 'Beta', '#C2410C');

    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto(`/admin/elections/${election.id}/ballots`);
    const zone = (await page.getByTestId('ballots-zone').boundingBox())!;
    const card = (await page.getByTestId('parties-card').boundingBox())!;
    expect(card.x).toBeGreaterThanOrEqual(zone.x + zone.width - 1);
    await expect(page.getByTestId('party-swatch-1')).toBeVisible();
    await expect(page.getByTestId('party-edit-1')).toBeVisible();

    await page.setViewportSize({ width: 360, height: 780 });
    await page.reload();
    await expect(page.getByTestId('party-row-2')).toBeVisible();
    await expectNoSidewaysScroll(page);
  });
});
