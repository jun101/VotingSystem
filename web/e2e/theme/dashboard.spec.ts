import { expect, test, type Page } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { cssOf, expectAccessible, expectNoSidewaysScroll, rgb } from '../support/checks';
import { createElection } from '../support/elections';

/*
 * The institution dashboard (docs/design/frontend.md, "Large screens, icons and the date picker", point 5).
 */

type Box = { x: number; right: number; y: number; width: number; height: number };
async function boxesOf(page: Page, selector: string): Promise<Box[]> {
  return page.locator(selector).evaluateAll((elements) =>
    elements.map((element) => {
      const r = element.getBoundingClientRect();
      return { x: Math.round(r.x), right: Math.round(r.right), y: Math.round(r.y), width: Math.round(r.width), height: Math.round(r.height) };
    }),
  );
}
const cards = '[data-testid^="dashboard-card-"]:not([data-testid^="dashboard-card-header-"])';
const distinct = (values: number[], tolerance = 3) =>
  values.sort((a, b) => a - b).filter((value, index, all) => index === 0 || value - all[index - 1]! > tolerance).length;

test.describe('a new institution', () => {
  test('is welcomed with three quick actions, the coral one for a new election [FR-NAV-01]', async ({ page }) => {
    await registerAndEnter(page, { name: 'Marie Joseph', institution: 'Collège Les Flamboyants' });

    await expect(page.getByTestId('dashboard-welcome')).toContainText('Marie Joseph');
    await expect(page.getByTestId('dashboard-institution')).toContainText('Collège Les Flamboyants');
    await expect(page.getByTestId('dashboard-quick-new-election')).toHaveAttribute('href', '/admin/elections/new');
    await expect(page.getByTestId('dashboard-quick-invite')).toHaveAttribute('href', /\/admin\/institution/);
    await expect(page.getByTestId('dashboard-quick-institution')).toHaveAttribute('href', '/admin/institution');
    expect(await cssOf(page, 'dashboard-quick-new-election', 'background-color')).toBe(rgb('#F29A76'));
    expect(await cssOf(page, 'dashboard-quick-new-election', 'color')).toBe(rgb('#1E2A5A'));
  });

  test('puts every card in one white zone, so the area shows even when a card is empty [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);

    expect(await cssOf(page, 'dashboard-zone', 'background-color')).toBe(rgb('#FFFFFF'));
    expect(parseFloat(await cssOf(page, 'dashboard-zone', 'border-top-width'))).toBeGreaterThanOrEqual(1);
    expect(parseFloat(await cssOf(page, 'dashboard-zone', 'border-top-left-radius'))).toBe(32);
    expect(await cssOf(page, 'admin-shell', 'background-color')).toBe(rgb('#F1F2FC'));

    const zone = (await boxesOf(page, '[data-testid="dashboard-zone"]'))[0]!;
    for (const card of await boxesOf(page, cards)) {
      expect(card.x).toBeGreaterThanOrEqual(zone.x);
      expect(card.right).toBeLessThanOrEqual(zone.right);
      expect(card.y).toBeGreaterThanOrEqual(zone.y);
    }
  });

  test('each card has a coloured header with an icon, and an empty card an icon circle [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);

    for (const key of ['open-election', 'todo', 'figures', 'activity', 'latest', 'getting-started']) {
      const header = page.getByTestId(`dashboard-card-header-${key}`);
      await expect(header).toBeVisible();
      expect(await header.evaluate((e) => getComputedStyle(e).backgroundImage)).toContain('linear-gradient');
      expect((await header.boundingBox())!.height).toBe(68);
      expect(await header.locator('svg').count()).toBeGreaterThan(0);
    }
    expect(await cssOf(page, 'dashboard-card-header-open-election', 'color')).toBe(rgb('#FFFFFF'));
    expect(await cssOf(page, 'dashboard-card-header-todo', 'color')).toBe(rgb('#1E2A5A'));
    expect(await page.getByTestId('dashboard-card-open-election').locator('[data-testid="empty-art"]').count()).toBe(1);
    await expect(page.getByTestId('dashboard-create-election')).toHaveAttribute('href', '/admin/elections/new');
  });

  test('shows the progress of the first steps, and a step turns done by itself [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);

    await expect(page.getByTestId('dashboard-progress')).toContainText('0 %');
    await expect(page.getByTestId('dashboard-step-election')).not.toHaveAttribute('data-done', 'true');

    await createElection(page, 'Ma première élection');
    await page.goto('/admin');

    await expect(page.getByTestId('dashboard-progress')).toContainText('33 %');
    await expect(page.getByTestId('dashboard-step-election')).toHaveAttribute('data-done', 'true');
  });

  test('the cards are at least 300 px tall and the layout follows the screen [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);
    const found = await boxesOf(page, cards);
    const columns = distinct(found.map((card) => card.x));
    const width = page.viewportSize()!.width;

    expect(found.length).toBe(6);
    for (const card of found) expect(card.height).toBeGreaterThanOrEqual(300);
    if (width < 768) expect(columns).toBe(1);
    else if (width < 1600) expect(columns).toBeGreaterThanOrEqual(2);
    await expectNoSidewaysScroll(page);
  });
});

test.describe('an institution with elections', () => {
  test('fills the cards with its data and keeps the open-election card honest [FR-NAV-01]', async ({ page }) => {
    await registerAndEnter(page);
    await createElection(page, 'Première', '2026-11-02T12:00:00Z', '2026-11-04T19:00:00Z');
    await createElection(page, 'Deuxième', '2026-11-10T12:00:00Z', '2026-11-11T19:00:00Z');
    const third = await createElection(page, 'Troisième', '2026-11-20T12:00:00Z', '2026-11-21T19:00:00Z');
    await page.goto('/admin');

    await expect(page.getByTestId('dashboard-figure-elections')).toHaveText('3');
    await expect(page.getByTestId('dashboard-figure-voters')).toHaveText('0');
    await expect(page.getByTestId('dashboard-figure-ballots')).toHaveText('0');

    await expect(page.locator('[data-testid^="dashboard-latest-"]')).toHaveCount(3);
    await expect(page.getByTestId('dashboard-latest-1')).toContainText('Troisième');
    await expect(page.getByTestId('dashboard-latest-1')).toContainText('Brouillon');

    await expect(page.locator('[data-testid^="dashboard-todo-"]')).toHaveCount(3);
    await expect(page.getByTestId('dashboard-todo-1')).toHaveAttribute('href', new RegExp(`/admin/elections/${third.id}/edit`));

    await expect(page.getByTestId('dashboard-create-election')).toBeVisible();
    await expect(page.getByTestId('dashboard-card-activity')).toContainText('Aucune activité');
    await expect(page.getByTestId('dashboard').locator('table')).toHaveCount(0);
  });
});

test.describe('on a large screen', () => {
  test.use({ viewport: { width: 1920, height: 1080 } });
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name === 'phone', 'a large-screen test');
  });

  test('three columns of cards at least 380 px wide, the zone filling the width [NFR-UX-02]', async ({ page }) => {
    await registerAndEnter(page);

    const found = await boxesOf(page, cards);
    expect(distinct(found.map((card) => card.x))).toBe(3);
    for (const card of found) expect(card.width).toBeGreaterThanOrEqual(380);
    const zone = (await boxesOf(page, '[data-testid="dashboard-zone"]'))[0]!;
    expect(1920 - zone.right).toBeLessThanOrEqual(40);
    expect(Math.max(...found.map((card) => card.right)) - zone.right).toBeLessThanOrEqual(0);
    expect(zone.right - Math.max(...found.map((card) => card.right))).toBeLessThanOrEqual(24);
  });
});

test('the dashboard is accessible in both states, with motion reduced too [NFR-UX-03]', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ reducedMotion: 'reduce', baseURL, locale: 'fr-FR' });
  const page = await context.newPage();
  await registerAndEnter(page);
  await expectAccessible(page);

  await createElection(page, 'Une');
  await page.goto('/admin');
  await expect(page.getByTestId('dashboard-latest-1')).toBeVisible();
  await expectNoSidewaysScroll(page);
  await expectAccessible(page);
  expect(await page.getByTestId('dashboard-progress').evaluate((e) => getComputedStyle(e).animationName)).toBe('none');
  await context.close();
});
