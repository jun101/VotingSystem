import { expect, test } from '@playwright/test';
import { isPhone, registerAndEnter } from '../support/admin';
import { cardTitles, createBallot, readBallots } from '../support/ballots';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { createElection } from '../support/elections';

/*
 * Slice 06a — the ballots page (screen A06): add, edit, delete and reorder the positions of a draft election.
 * docs/slices/06a-ballots.md. API: docs/api/ballots/. Design: docs/design/frontend.md 1.4 items 9 to 11 and
 * docs/design/mockups/material-ballots.html.
 */

test.describe('an election with no ballot', () => {
  test('shows the page with its creation tile and a count of zero [FR-BAL-01]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Conseil des élèves 2026');

    await page.goto(`/admin/elections/${election.id}/ballots`);

    await expect(page.getByTestId('ballots-page')).toBeVisible();
    await expect(page.getByTestId('ballots-title')).toHaveText('Postes et candidats');
    await expect(page.getByTestId('ballots-count')).toContainText('0');
    await expect(page.getByTestId('ballot-new-tile')).toBeVisible();
    await expect(page.locator('[data-testid^="ballot-card-"]')).toHaveCount(0);
  });

  test('is reached from the first next step of the election page [FR-BAL-01, FR-NAV-02]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Depuis la page');

    await page.goto(`/admin/elections/${election.id}`);
    await page.getByTestId('election-step-1').click();

    await expect(page).toHaveURL(new RegExp(`/admin/elections/${election.id}/ballots$`));
    await expect(page.getByTestId('ballots-page')).toBeVisible();
  });
});

test.describe('adding a ballot', () => {
  test('from the main button, with the defaults of one seat and a blank vote [FR-BAL-01, FR-BAL-02]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Ajouter');
    await page.goto(`/admin/elections/${election.id}/ballots`);

    await page.getByTestId('ballots-add').click();
    await page.getByTestId('ballot-form-title').fill('Président(e)');
    await page.getByTestId('ballot-form-save').click();

    await expect(page.getByTestId('ballot-title-1')).toHaveText('Président(e)');
    await expect(page.getByTestId('ballot-seats-1')).toContainText('1 siège');
    await expect(page.getByTestId('ballot-blank-1')).toContainText('Vote blanc');
    await expect(page.getByTestId('ballots-count')).toContainText('1');
    expect((await readBallots(page, election.id)).map((b) => b.title)).toEqual(['Président(e)']);
  });

  test('from the creation tile, with several seats and no blank vote [FR-BAL-02]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Sièges');
    await page.goto(`/admin/elections/${election.id}/ballots`);

    await page.getByTestId('ballot-new-tile').click();
    await page.getByTestId('ballot-form-title').fill('Délégués');
    await page.getByTestId('ballot-form-description').fill('Un par classe');
    await page.getByTestId('ballot-form-seats').fill('3');
    await page.getByTestId('ballot-form-blank').uncheck();
    await page.getByTestId('ballot-form-save').click();

    await expect(page.getByTestId('ballot-seats-1')).toContainText('3 sièges');
    await expect(page.getByTestId('ballot-blank-1')).toContainText('Sans vote blanc');
    const [ballot] = await readBallots(page, election.id);
    expect(ballot).toMatchObject({ title: 'Délégués', seats: 3, allow_blank: false });
  });

  test('refuses an empty title with a message beside the field, and keeps the form open [FR-BAL-01]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Erreurs');
    await page.goto(`/admin/elections/${election.id}/ballots`);

    await page.getByTestId('ballots-add').click();
    await page.getByTestId('ballot-form-save').click();

    await expect(page.getByTestId('ballot-form-error-title')).toBeVisible();
    await expect(page.getByTestId('ballot-form')).toBeVisible();
    expect(await readBallots(page, election.id)).toEqual([]);
  });

  test('can be cancelled without saving anything [FR-BAL-01]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Annuler');
    await page.goto(`/admin/elections/${election.id}/ballots`);

    await page.getByTestId('ballots-add').click();
    await page.getByTestId('ballot-form-title').fill('Abandonné');
    await page.getByTestId('ballot-form-cancel').click();

    await expect(page.getByTestId('ballot-form')).toHaveCount(0);
    expect(await readBallots(page, election.id)).toEqual([]);
  });
});

test.describe('editing and deleting', () => {
  test('edits a ballot in place [FR-BAL-01, FR-BAL-02]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Modifier');
    await createBallot(page, election.id, 'Secrétaire');
    await page.goto(`/admin/elections/${election.id}/ballots`);

    await page.getByTestId('ballot-edit-1').click();
    await expect(page.getByTestId('ballot-form-title')).toHaveValue('Secrétaire');
    await page.getByTestId('ballot-form-title').fill('Secrétaire général');
    await page.getByTestId('ballot-form-seats').fill('2');
    await page.getByTestId('ballot-form-save').click();

    await expect(page.getByTestId('ballot-title-1')).toHaveText('Secrétaire général');
    await expect(page.getByTestId('ballot-seats-1')).toContainText('2 sièges');
    await page.reload();
    await expect(page.getByTestId('ballot-title-1')).toHaveText('Secrétaire général');
  });

  test('deletes a ballot after a confirmation that names it, and the positions close up [FR-BAL-01]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Supprimer');
    await createBallot(page, election.id, 'Un');
    await createBallot(page, election.id, 'Deux');
    await createBallot(page, election.id, 'Trois');
    await page.goto(`/admin/elections/${election.id}/ballots`);

    await page.getByTestId('ballot-delete-2').click();
    await expect(page.getByTestId('ballot-delete-dialog')).toContainText('Deux');
    await page.getByTestId('ballot-delete-cancel').click();
    await expect(page.getByTestId('ballot-delete-dialog')).toHaveCount(0);
    expect(await cardTitles(page)).toEqual(['Un', 'Deux', 'Trois']);

    await page.getByTestId('ballot-delete-2').click();
    await page.getByTestId('ballot-delete-confirm').click();

    await expect.poll(() => cardTitles(page)).toEqual(['Un', 'Trois']);
    await expect(page.getByTestId('ballots-count')).toContainText('2');
    expect((await readBallots(page, election.id)).map((b) => b.position)).toEqual([1, 2]);
  });
});

test.describe('reordering', () => {
  test('with the up and down buttons, saved at once and kept after a reload [FR-BAL-01]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Ordre');
    await createBallot(page, election.id, 'A');
    await createBallot(page, election.id, 'B');
    await createBallot(page, election.id, 'C');
    await page.goto(`/admin/elections/${election.id}/ballots`);

    await expect(page.getByTestId('ballot-up-1')).toBeDisabled();
    await expect(page.getByTestId('ballot-down-3')).toBeDisabled();

    await page.getByTestId('ballot-down-1').click();
    await expect.poll(() => cardTitles(page)).toEqual(['B', 'A', 'C']);
    await page.getByTestId('ballot-up-3').click();
    await expect.poll(() => cardTitles(page)).toEqual(['B', 'C', 'A']);

    await page.reload();
    expect(await cardTitles(page)).toEqual(['B', 'C', 'A']);
    expect((await readBallots(page, election.id)).map((b) => b.title)).toEqual(['B', 'C', 'A']);
  });

  test('by dragging the grip of a card onto another [FR-BAL-01]', async ({ page }) => {
    test.skip(isPhone(page), 'drag and drop is a pointer path; phones use the buttons');
    await registerAndEnter(page);
    const election = await createElection(page, 'Glisser');
    await createBallot(page, election.id, 'A');
    await createBallot(page, election.id, 'B');
    await createBallot(page, election.id, 'C');
    await page.goto(`/admin/elections/${election.id}/ballots`);

    await page.getByTestId('ballot-grip-3').dragTo(page.getByTestId('ballot-card-1'));

    await expect.poll(() => cardTitles(page)).toEqual(['C', 'A', 'B']);
    await page.reload();
    expect(await cardTitles(page)).toEqual(['C', 'A', 'B']);
  });
});

test.describe('the election list and the duplicate', () => {
  test('the election card shows how many ballots it has [FR-ELEC-07]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Avec des postes');
    await createBallot(page, election.id, 'A');
    await createBallot(page, election.id, 'B');

    await page.goto('/admin/elections');

    await expect(page.getByTestId('election-card-1')).toContainText('2 postes');
  });

  test('duplicating an election copies its ballots, in order [FR-ELEC-06]', async ({ page }) => {
    await registerAndEnter(page);
    const election = await createElection(page, 'Source');
    await createBallot(page, election.id, 'Premier', { seats: 2 });
    await createBallot(page, election.id, 'Second');
    await page.goto('/admin/elections');

    await page.getByTestId('election-duplicate-1').click();

    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(2);
    const list = await page.request.get('/api/v1/elections');
    const copy = ((await list.json()) as { data: { id: string; title: string }[] }).data.find((e) => e.id !== election.id);
    expect(copy).toBeDefined();
    const ballots = await readBallots(page, copy!.id);
    expect(ballots.map((b) => b.title)).toEqual(['Premier', 'Second']);
    expect(ballots[0]).toMatchObject({ seats: 2, position: 1 });
    expect(ballots.map((b) => b.id)).not.toContain((await readBallots(page, election.id))[0].id);
  });
});

test.describe('the layout adapts to the screen (frontend.md 1.4 items 10 and 11)', () => {
  async function sixBallots(page: import('@playwright/test').Page): Promise<string> {
    await registerAndEnter(page);
    const election = await createElection(page, 'Écrans');
    for (const title of ['A', 'B', 'C', 'D', 'E', 'F']) await createBallot(page, election.id, title);
    return election.id;
  }

  async function columns(page: import('@playwright/test').Page): Promise<number> {
    const lefts = await page
      .locator('[data-testid^="ballot-card-"]')
      .evaluateAll((cards) => cards.map((card) => Math.round(card.getBoundingClientRect().left)));
    return new Set(lefts).size;
  }

  test('on a 1920 by 1080 screen: three columns, the rail beside the zone, no sideways scroll', async ({ page }) => {
    const id = await sixBallots(page);
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto(`/admin/elections/${id}/ballots`);

    expect(await columns(page)).toBe(3);
    const zone = await page.getByTestId('ballots-zone').boundingBox();
    const rail = await page.getByTestId('ballots-rail').boundingBox();
    expect(rail!.x).toBeGreaterThanOrEqual(zone!.x + zone!.width - 1);
    expect(rail!.width).toBeGreaterThanOrEqual(330);
    await expectNoSidewaysScroll(page);
  });

  test('on a 1280 screen: three columns filling the width, the rail under the zone', async ({ page }) => {
    const id = await sixBallots(page);
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.goto(`/admin/elections/${id}/ballots`);

    expect(await columns(page)).toBe(3);
    const zone = await page.getByTestId('ballots-zone').boundingBox();
    const rail = await page.getByTestId('ballots-rail').boundingBox();
    expect(rail!.y).toBeGreaterThanOrEqual(zone!.y + zone!.height - 1);
    const main = await page.locator('main').boundingBox();
    expect(zone!.width).toBeGreaterThan(main!.width - 80);
    await expectNoSidewaysScroll(page);
  });

  test('on a 2560 screen: four columns', async ({ page }) => {
    const id = await sixBallots(page);
    await page.setViewportSize({ width: 2560, height: 1440 });
    await page.goto(`/admin/elections/${id}/ballots`);

    expect(await columns(page)).toBe(4);
    await expectNoSidewaysScroll(page);
  });

  test('on a phone: one column, the side menu is a drawer, the main button keeps its icon beside its text', async ({ page }) => {
    const id = await sixBallots(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await page.goto(`/admin/elections/${id}/ballots`);

    expect(await columns(page)).toBe(1);
    await expect(page.getByTestId('menu-button')).toBeVisible();
    await expectNoSidewaysScroll(page);
    const add = page.getByTestId('ballots-add');
    expect(await add.evaluate((el) => getComputedStyle(el).flexDirection)).not.toBe('column');
    const icon = await add.locator('svg').boundingBox();
    const box = await add.boundingBox();
    expect(icon!.y).toBeGreaterThanOrEqual(box!.y);
    expect(icon!.y + icon!.height).toBeLessThanOrEqual(box!.y + box!.height);
  });

  test('has no accessibility violation on a desktop and on a phone', async ({ page }) => {
    const id = await sixBallots(page);
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto(`/admin/elections/${id}/ballots`);
    await expectAccessible(page);

    await page.setViewportSize({ width: 360, height: 780 });
    await page.reload();
    await expectAccessible(page);
  });
});
