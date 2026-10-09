import { expect, test, type Page } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { cssOf, expectAccessible, expectNoSidewaysScroll, rgb } from '../support/checks';
import { createElection, createSix } from '../support/elections';

/*
 * The elections list on a large screen, 1920 x 1080 (docs/design/frontend.md, "Large screens, icons
 * and the date picker", points 1 to 3), and at 1280 where the rail is not shown.
 */

const WIDE = { width: 1920, height: 1080 };

type Box = { x: number; right: number; y: number; width: number; height: number };
async function boxesOf(page: Page, selector: string): Promise<Box[]> {
  return page.locator(selector).evaluateAll((elements) =>
    elements.map((element) => {
      const r = element.getBoundingClientRect();
      return { x: Math.round(r.x), right: Math.round(r.right), y: Math.round(r.y), width: Math.round(r.width), height: Math.round(r.height) };
    }),
  );
}

const plusDays = (days: number) => new Date(Date.now() + days * 86_400_000);
const localDay = (date: Date) => date.toLocaleDateString('sv-SE', { timeZone: 'America/Port-au-Prince' });

test.describe('at 1920 x 1080', () => {
  test.use({ viewport: WIDE });
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name === 'phone', 'a large-screen test');
  });

  test('the cards are big and the grid fills the width beside the rail, nothing left empty [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await createSix(page);
    await page.goto('/admin/elections');
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(6);

    const cards = await boxesOf(page, '[data-testid^="election-card-"]');
    // The creation tile is the first cell of the grid, so a row counts it.
    const cells = await boxesOf(page, '[data-testid="election-new-tile"], [data-testid^="election-card-"]');
    const grid = (await boxesOf(page, '[data-testid="elections-grid"]'))[0]!;
    const rail = (await boxesOf(page, '[data-testid="elections-rail"]'))[0]!;

    for (const card of cards) expect(card.width).toBeGreaterThanOrEqual(380);
    const firstRow = cells.filter((cell) => Math.abs(cell.y - cells[0]!.y) <= 3);
    expect(firstRow.length).toBeGreaterThanOrEqual(3);
    // The grid reaches the rail and the rail reaches the right edge of the content.
    expect(rail.x - grid.right).toBeLessThanOrEqual(24);
    expect(WIDE.width - rail.right).toBeLessThanOrEqual(40);
    expect(rail.width).toBeGreaterThanOrEqual(320);
    // The last card of a full row ends where the grid ends.
    expect(grid.right - Math.max(...firstRow.map((cell) => cell.right))).toBeLessThanOrEqual(20);
    await expectNoSidewaysScroll(page);
  });

  test('an election card has a 120 px cover with its status badge and three fact tiles with icons [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await createElection(page, 'Conseil des élèves', '2026-11-02T12:00:00Z', '2026-11-04T19:00:00Z');
    await page.goto('/admin/elections');

    expect((await page.getByTestId('election-band-1').boundingBox())!.height).toBe(120);
    await expect(page.getByTestId('election-badge-1')).toContainText('Brouillon');
    expect(await page.getByTestId('election-badge-1').locator('svg').count()).toBeGreaterThan(0);
    for (const fact of ['positions', 'voters', 'ballots']) {
      const tile = page.getByTestId(`election-fact-${fact}-1`);
      await expect(tile).toBeVisible();
      expect(await tile.locator('svg').count()).toBeGreaterThan(0);
      await expect(tile.locator('[data-testid$="-value"]')).toHaveText('0');
    }
    for (const action of ['open', 'duplicate', 'delete']) {
      expect(await page.getByTestId(`election-${action}-1`).locator('svg').count()).toBeGreaterThan(0);
    }
  });

  test('the status tiles and the side menu carry icons [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections');

    expect(await page.getByTestId('tile-all').locator('svg').count()).toBeGreaterThan(0);
    expect(await page.getByTestId('menu-link-elections').locator('svg').count()).toBeGreaterThan(0);
  });

  test('the rail has a calendar marking the day an election starts, on the month of the next election [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    const start = plusDays(10);
    await createElection(page, 'Bientôt', start.toISOString().slice(0, 19) + 'Z', plusDays(12).toISOString().slice(0, 19) + 'Z');
    await page.goto('/admin/elections');

    await expect(page.getByTestId('rail-calendar')).toBeVisible();
    await expect(page.getByTestId(`rail-day-${localDay(start)}`)).toHaveAttribute('data-event', 'true');
  });

  test('the rail lists what is left to do, and invites a first election when there is none [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections');
    await expect(page.getByTestId('rail-todo-empty')).toBeVisible();

    const election = await createElection(page, 'À terminer', '2026-11-02T12:00:00Z', '2026-11-04T19:00:00Z');
    await page.goto('/admin/elections');

    await expect(page.getByTestId('rail-todo-empty')).toHaveCount(0);
    const item = page.getByTestId('rail-todo-1');
    await expect(item).toContainText('À terminer');
    await expect(item).toHaveAttribute('href', new RegExp(`/admin/elections/${election.id}`));
    expect(await item.locator('svg').count()).toBeGreaterThan(0);
  });

  test('the list, the rail and the form are accessible at this size [NFR-UX-03]', async ({ page }) => {
    await registerAndEnter(page);
    await createSix(page);
    await page.goto('/admin/elections');
    await expect(page.getByTestId('elections-rail')).toBeVisible();
    await expectAccessible(page);

    await page.goto('/admin/elections/new');
    await expectNoSidewaysScroll(page);
    await expectAccessible(page);
  });
});

test.describe('at 1280 x 800', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name === 'phone', 'a desktop test');
  });

  test('the rail is not shown, never squeezed, and the grid keeps three columns [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await createSix(page);
    await page.goto('/admin/elections');
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(6);

    await expect(page.getByTestId('elections-rail')).toBeHidden();
    const cards = await boxesOf(page, '[data-testid^="election-card-"]');
    const firstRow = cards.filter((card) => Math.abs(card.y - cards[0]!.y) <= 3);
    expect(firstRow.length).toBeGreaterThanOrEqual(2);
    await expectNoSidewaysScroll(page);
  });
});

test.describe('the white zone of the list', () => {
  test('wraps the creation tile and the cards, also when the list is empty [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections');

    expect(await cssOf(page, 'elections-zone', 'background-color')).toBe(rgb('#FFFFFF'));
    expect(parseFloat(await cssOf(page, 'elections-zone', 'border-top-width'))).toBeGreaterThanOrEqual(1);
    expect(parseFloat(await cssOf(page, 'elections-zone', 'border-top-left-radius'))).toBe(32);
    expect(await cssOf(page, 'admin-shell', 'background-color')).toBe(rgb('#F1F2FC'));
    const zone = (await boxesOf(page, '[data-testid="elections-zone"]'))[0]!;
    const tile = (await boxesOf(page, '[data-testid="election-new-tile"]'))[0]!;
    expect(tile.x).toBeGreaterThanOrEqual(zone.x);
    expect(tile.right).toBeLessThanOrEqual(zone.right);
    expect(tile.y).toBeGreaterThanOrEqual(zone.y);

    await createSix(page);
    await page.goto('/admin/elections');
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(6);
    const zoneFull = (await boxesOf(page, '[data-testid="elections-zone"]'))[0]!;
    for (const card of await boxesOf(page, '[data-testid^="election-card-"]')) {
      expect(card.x).toBeGreaterThanOrEqual(zoneFull.x);
      expect(card.right).toBeLessThanOrEqual(zoneFull.right);
      expect(card.y + card.height).toBeLessThanOrEqual(zoneFull.y + zoneFull.height);
    }
    // The tiles, chips and button stay above the zone, on the page background.
    const tiles = (await boxesOf(page, '[data-testid="tile-all"]'))[0]!;
    expect(tiles.y + tiles.height).toBeLessThanOrEqual(zoneFull.y + 1);
    await expectNoSidewaysScroll(page);
  });
});
