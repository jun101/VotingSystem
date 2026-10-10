import { expect, test, type Page } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { cssOf, expectAccessible, expectNoSidewaysScroll, rgb } from '../support/checks';
import { createElection, putCover } from '../support/elections';

/*
 * The election page and form (docs/design/frontend.md, "Large screens, icons and the date picker", points 7 and 8).
 */

type Box = { x: number; right: number; y: number; width: number; height: number };
async function boxOf(page: Page, id: string): Promise<Box> {
  return page.getByTestId(id).evaluate((element) => {
    const r = element.getBoundingClientRect();
    return { x: Math.round(r.x), right: Math.round(r.right), y: Math.round(r.y), width: Math.round(r.width), height: Math.round(r.height) };
  });
}
const CARDS = ['calendar', 'settings', 'cover', 'facts', 'steps'];

async function openSummary(page: Page, extra: Record<string, unknown> = {}) {
  await registerAndEnter(page);
  const election = await createElection(page, 'Conseil des élèves', '2026-11-02T12:00:00Z', '2026-11-06T19:00:00Z', extra);
  await page.goto(`/admin/elections/${election.id}`);
  await expect(page.getByTestId('election-hero')).toBeVisible();
  return election;
}

test.describe('summary page', () => {
  test('opens on a gradient band with the badge, the title, the chips and the actions [NFR-UX-02]', async ({ page }) => {
    await openSummary(page, { description: 'Élection annuelle.' });

    const hero = await boxOf(page, 'election-hero');
    expect(hero.height).toBeGreaterThanOrEqual(160);
    expect(await cssOf(page, 'election-hero', 'background-image')).toContain('linear-gradient');
    await expect(page.getByTestId('election-summary-title')).toHaveText('Conseil des élèves');
    expect(await cssOf(page, 'election-summary-title', 'color')).toBe(rgb('#FFFFFF'));
    await expect(page.getByTestId('election-hero-badge')).toContainText('Brouillon');
    expect(await page.getByTestId('election-hero-badge').locator('svg').count()).toBeGreaterThan(0);
    await expect(page.getByTestId('election-chip-dates')).toContainText('2 nov.');
    await expect(page.getByTestId('election-chip-duration')).toContainText('4 jours');
    await expect(page.getByTestId('election-chip-language')).toContainText('Français');
    // The edit action is the coral one.
    expect(await cssOf(page, 'election-edit', 'background-color')).toBe(rgb('#F29A76'));
    expect(await cssOf(page, 'election-edit', 'color')).toBe(rgb('#1E2A5A'));
    for (const action of ['election-edit', 'election-duplicate', 'election-delete']) {
      expect(await page.getByTestId(action).locator('svg').count()).toBeGreaterThan(0);
    }
  });

  test('puts five cards with coloured headers and icons inside one white zone [NFR-UX-02]', async ({ page }) => {
    await openSummary(page);

    expect(await cssOf(page, 'election-zone', 'background-color')).toBe(rgb('#FFFFFF'));
    expect(parseFloat(await cssOf(page, 'election-zone', 'border-top-left-radius'))).toBe(32);
    const zone = await boxOf(page, 'election-zone');
    for (const key of CARDS) {
      const card = await boxOf(page, `election-card-${key}`);
      expect(card.x).toBeGreaterThanOrEqual(zone.x);
      expect(card.right).toBeLessThanOrEqual(zone.right);
      const header = page.getByTestId(`election-card-header-${key}`);
      expect(await header.evaluate((e) => getComputedStyle(e).backgroundImage)).toContain('linear-gradient');
      expect((await header.boundingBox())!.height).toBe(64);
      expect(await header.locator('svg').count()).toBeGreaterThan(0);
    }
  });

  test('says the calendar with day badges, the times, the duration and the time zone [FR-ELEC-02]', async ({ page }) => {
    await openSummary(page);

    await expect(page.getByTestId('election-day-start')).toContainText('2');
    await expect(page.getByTestId('election-day-end')).toContainText('6');
    // 12:00 and 19:00 UTC on 2 and 6 November, after the clock change: UTC-5 in Port-au-Prince.
    await expect(page.getByTestId('election-card-calendar')).toContainText('07:00');
    await expect(page.getByTestId('election-card-calendar')).toContainText('14:00');
    await expect(page.getByTestId('election-duration-line')).toContainText('4 jours');
    await expect(page.getByTestId('election-summary-dates')).toContainText('2 novembre');
    await expect(page.getByTestId('election-summary-timezone')).toContainText('Port-au-Prince');
  });

  test('lists the settings as icon rows and shows only real figures [FR-ELEC-01]', async ({ page }) => {
    await openSummary(page, { language: 'en', candidate_order: 'shuffled', results_display: 'winners' });

    for (const row of ['language', 'order', 'results']) {
      const setting = page.getByTestId(`election-setting-${row}`);
      await expect(setting).toBeVisible();
      expect(await setting.locator('svg').count()).toBeGreaterThan(0);
    }
    await expect(page.getByTestId('election-setting-language')).toContainText('English');
    await expect(page.getByTestId('election-setting-order')).toContainText('Mélangé');
    for (const fact of ['positions', 'voters', 'ballots']) {
      await expect(page.getByTestId(`election-fact-${fact}`)).toContainText('0');
      expect(await page.getByTestId(`election-fact-${fact}`).locator('svg').count()).toBeGreaterThan(0);
    }
    await expect(page.getByTestId('election-card-settings')).not.toContainText('secret');
  });

  test('shows the four next steps as a path: the first is ready (slice 06), the others coming soon [FR-ELEC-01]', async ({ page }) => {
    await openSummary(page);

    for (const n of [1, 2, 3, 4]) {
      const step = page.getByTestId(`election-step-${n}`);
      await expect(step).toHaveAttribute('data-state', n === 1 ? 'ready' : 'soon');
      expect(await step.locator('svg').count()).toBeGreaterThan(0);
    }
    const first = await boxOf(page, 'election-step-1');
    const last = await boxOf(page, 'election-step-4');
    const width = page.viewportSize()!.width;
    if (width >= 1100) expect(Math.abs(first.y - last.y)).toBeLessThanOrEqual(4);
  });

  test('the cover card shows the picture when there is one, a gradient when not [FR-ELEC-04]', async ({ page }) => {
    const election = await openSummary(page);
    // The id sits on the picture itself, and only when there is one (slice 05 tests).
    await expect(page.getByTestId('election-card-cover')).toBeVisible();
    await expect(page.getByTestId('election-summary-cover')).toHaveCount(0);

    await putCover(page, election.id);
    await page.reload();

    await expect(page.getByTestId('election-summary-cover')).toBeVisible();
    await expect(page.getByTestId('election-card-cover').locator('img')).toHaveCount(1);
  });

  test('fills a large screen: no card row leaves a gap on the right [NFR-UX-02]', async ({ page }) => {
    test.skip(page.viewportSize()!.width < 1600, 'a large-screen check');
    await openSummary(page);

    const zone = await boxOf(page, 'election-zone');
    const rights = await Promise.all(CARDS.map(async (key) => (await boxOf(page, `election-card-${key}`)).right));
    expect(zone.right - Math.max(...rights)).toBeLessThanOrEqual(24);
    const hero = await boxOf(page, 'election-hero');
    expect(Math.abs(hero.right - zone.right)).toBeLessThanOrEqual(2);
  });

  test('is accessible and never scrolls sideways, with and without a cover [NFR-UX-03]', async ({ page }) => {
    const election = await openSummary(page);
    await expectNoSidewaysScroll(page);
    await expectAccessible(page);

    await putCover(page, election.id);
    await page.reload();
    await expectNoSidewaysScroll(page);
    await expectAccessible(page);
  });
});

test.describe('form', () => {
  test('has the white zone and a coloured header with an icon on each section [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections/new');

    expect(await cssOf(page, 'election-form-zone', 'background-color')).toBe(rgb('#FFFFFF'));
    expect(parseFloat(await cssOf(page, 'election-form-zone', 'border-top-left-radius'))).toBe(32);
    for (const key of ['info', 'calendar', 'settings', 'cover']) {
      const header = page.getByTestId(`election-section-header-${key}`);
      await expect(header).toBeVisible();
      expect(await header.evaluate((e) => getComputedStyle(e).backgroundImage)).toContain('linear-gradient');
      expect(await header.locator('svg').count()).toBeGreaterThan(0);
    }
    await expectNoSidewaysScroll(page);
    await expectAccessible(page);
  });
});
