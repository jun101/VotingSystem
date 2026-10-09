import { expect, test, type Page } from '@playwright/test';
import { isPhone, registerAndEnter } from '../support/admin';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { createElection, createSix, putCover } from '../support/elections';

/*
 * Slice 05 — accessibility, width, density, touch size and motion of the elections screens. Each
 * project runs these at its own width: 1280 px (desktop) and 320 px (phone).
 * docs/slices/05-elections.md, rule 8 and the design rule: as little free space as possible.
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

const distinct = (values: number[], tolerance = 3) =>
  values.sort((a, b) => a - b).filter((value, index, all) => index === 0 || value - all[index - 1]! > tolerance).length;

for (const language of [
  { locale: 'fr-FR', lang: 'fr' },
  { locale: 'en-US', lang: 'en' },
]) {
  test.describe(`in ${language.lang}`, () => {
    test.use({ locale: language.locale });

    test('the list is accessible and never scrolls sideways, empty and full [NFR-UX-03, FR-NAV-03]', async ({ page }) => {
      await registerAndEnter(page);
      await page.goto('/admin/elections');
      await expect(page.locator('html')).toHaveAttribute('lang', language.lang);
      await expect(page.getByTestId('elections-empty')).toBeVisible();
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);

      await createSix(page);
      const first = await createElection(page, 'Avec affiche', '2026-11-02T12:00:00Z', '2026-11-03T12:00:00Z');
      await putCover(page, first.id);
      await page.goto('/admin/elections');
      await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(7);
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);
    });

    test('the form and the summary page are accessible, with errors showing [NFR-UX-03]', async ({ page }) => {
      await registerAndEnter(page);
      await page.goto('/admin/elections/new');
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);

      await page.getByTestId('election-save').click();
      await expect(page.getByTestId('election-title-error')).toBeVisible();
      await expectAccessible(page);

      const election = await createElection(page, 'Accessible');
      await page.goto(`/admin/elections/${election.id}`);
      await expect(page.getByTestId('election-page')).toBeVisible();
      await expectNoSidewaysScroll(page);
      await expectAccessible(page);

      await page.goto('/admin/elections');
      await page.getByTestId('election-delete-1').click();
      await expect(page.getByTestId('election-delete-dialog')).toBeVisible();
      await expectAccessible(page);
    });

    test('the delete dialog traps the focus, closes with Escape and gives the focus back [NFR-UX-03]', async ({ page }) => {
      await registerAndEnter(page);
      await createElection(page, 'Focus');
      await page.goto('/admin/elections');
      const button = page.getByTestId('election-delete-1');
      await button.focus();
      await button.press('Enter');
      await expect(page.getByTestId('election-delete-dialog')).toBeVisible();

      for (let i = 0; i < 6; i++) {
        await page.keyboard.press('Tab');
        const inside = await page.evaluate(
          () => document.querySelector('[data-testid="election-delete-dialog"]')?.contains(document.activeElement) ?? false,
        );
        expect(inside, `focus left the dialog after ${i + 1} Tab`).toBe(true);
      }

      await page.keyboard.press('Escape');
      await expect(page.getByTestId('election-delete-dialog')).toHaveCount(0);
      await expect(button).toBeFocused();
    });

    test('the controls are at least 44 px high, to be used with a thumb [FR-NAV-03]', async ({ page }) => {
      await registerAndEnter(page);
      await createElection(page, 'Pouce');
      await page.goto('/admin/elections');
      for (const id of ['election-open-1', 'election-duplicate-1', 'election-delete-1', 'tile-all', 'election-new-button']) {
        const box = await page.getByTestId(id).boundingBox();
        expect(box?.height ?? 0, id).toBeGreaterThanOrEqual(44);
      }
      await page.goto('/admin/elections/new');
      for (const id of ['election-title', 'election-save']) {
        const box = await page.getByTestId(id).boundingBox();
        expect(box?.height ?? 0, id).toBeGreaterThanOrEqual(44);
      }
    });
  });
}

test.describe('as little free space as possible [design rule of 2026-10-08]', () => {
  test('the card grid has 3 columns on a desktop and 1 on a phone, and its cards fill the width [FR-NAV-04]', async ({ page }) => {
    await registerAndEnter(page);
    await createSix(page);
    await page.goto('/admin/elections');
    await expect(page.locator('[data-testid^="election-card-"]')).toHaveCount(6);

    // The cells of the grid: the "new" tile and the six cards.
    const cells = await boxesOf(page, '[data-testid="elections-grid"] > *');
    expect(cells).toHaveLength(7);
    const grid = (await boxesOf(page, '[data-testid="elections-grid"]'))[0]!;
    const viewport = page.viewportSize()!;

    if (isPhone(page)) {
      expect(distinct(cells.map((cell) => cell.x)), 'columns on a phone').toBe(1);
      for (const cell of cells) expect(cell.width, 'a card is as wide as the screen allows').toBeGreaterThanOrEqual(viewport.width - 48);
    } else {
      expect(distinct(cells.map((cell) => cell.x)), 'columns on a desktop').toBe(3);
      // The last column reaches the right edge of the grid (no dead strip), and the grid itself reaches the right of the screen.
      expect(Math.max(...cells.map((cell) => cell.right))).toBeGreaterThanOrEqual(grid.right - 2);
      expect(viewport.width - grid.right, 'free strip to the right of the grid').toBeLessThanOrEqual(64);
      // Gaps between neighbours stay small.
      const firstRow = cells.filter((cell) => Math.abs(cell.y - cells[0]!.y) <= 3).sort((a, b) => a.x - b.x);
      for (let i = 1; i < firstRow.length; i++) expect(firstRow[i]!.x - firstRow[i - 1]!.right, 'gap').toBeLessThanOrEqual(24);
    }
  });

  test('the status tiles and the year chips are one compact row on a desktop [FR-ELEC-07]', async ({ page }) => {
    test.skip(isPhone(page), 'on a phone the row may wrap');
    await registerAndEnter(page);
    await createSix(page);
    await page.goto('/admin/elections');

    const row = await boxesOf(page, '[data-testid="tile-all"], [data-testid="tile-draft"], [data-testid="year-2026"], [data-testid="year-2025"], [data-testid="election-new-button"]');
    expect(row).toHaveLength(5);
    expect(distinct(row.map((box) => box.y)), 'rows of tiles and chips').toBe(1);
    expect(Math.max(...row.map((box) => box.height)), 'height of the row').toBeLessThanOrEqual(72);
  });

  test('the form has its side panel beside the fields on a desktop, under them on a phone [FR-ELEC-01]', async ({ page }) => {
    await registerAndEnter(page);
    await page.goto('/admin/elections/new');

    const title = (await boxesOf(page, '[data-testid="election-title"]'))[0]!;
    const side = (await boxesOf(page, '[data-testid="election-schedule"]'))[0]!;
    const viewport = page.viewportSize()!;

    if (isPhone(page)) {
      expect(side.y, 'the panel comes after the fields').toBeGreaterThan(title.y);
      expect(side.width).toBeGreaterThanOrEqual(viewport.width - 48);
    } else {
      expect(side.x, 'the panel is to the right of the fields').toBeGreaterThan(title.right);
      expect(viewport.width - side.right, 'free strip to the right of the panel').toBeLessThanOrEqual(64);
      // The fields and the panel fill the width between them: no gap wider than 64 px.
      expect(side.x - title.right).toBeLessThanOrEqual(64);
    }
    await expectNoSidewaysScroll(page);
  });

  test('the summary page is two columns on a desktop [FR-ELEC-01]', async ({ page }) => {
    test.skip(isPhone(page), 'one column on a phone');
    await registerAndEnter(page);
    const election = await createElection(page, 'Deux colonnes');
    await page.goto(`/admin/elections/${election.id}`);

    const facts = (await boxesOf(page, '[data-testid="election-summary-dates"]'))[0]!;
    const next = (await boxesOf(page, '[data-testid="election-next-steps"]'))[0]!;
    expect(next.x, 'the next steps sit beside the facts').toBeGreaterThan(facts.right - 8);
  });
});

test.describe('"reduce motion"', () => {
  test.use({ reducedMotion: 'reduce' });

  test('the list is complete and still [NFR-UX-03]', async ({ page }) => {
    await registerAndEnter(page);
    await createSix(page);
    await page.goto('/admin/elections');
    await expect(page.getByTestId('elections-grid')).toBeVisible();

    const moving = await page.evaluate(() =>
      document.getAnimations().filter((animation) => animation.playState === 'running' && !(animation instanceof CSSTransition)).length,
    );
    expect(moving, 'running animations').toBe(0);
    const opacity = await page.getByTestId('election-card-1').evaluate((element) => parseFloat(getComputedStyle(element).opacity));
    expect(opacity).toBe(1);
  });
});

test.describe('motion allowed', () => {
  test.use({ reducedMotion: 'no-preference' });

  test('the cards come in by revealing, and end fully visible [NFR-UX-03]', async ({ page }) => {
    await registerAndEnter(page);
    await createSix(page);
    await page.goto('/admin/elections');

    await expect
      .poll(async () => page.getByTestId('election-card-6').evaluate((element) => parseFloat(getComputedStyle(element).opacity)), { timeout: 5_000 })
      .toBe(1);
  });
});
