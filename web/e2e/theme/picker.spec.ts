import { expect, test, type Page } from '@playwright/test';
import { isPhone, registerAndEnter } from '../support/admin';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { readElection } from '../support/elections';

/*
 * The date and time picker of the election form (docs/design/frontend.md, "Large screens, icons and
 * the date picker", point 4). The two native datetime-local fields stay in the page, hidden, as the
 * keyboard path and the value the form reads; the tiles are the pointer interface and write to them.
 */

async function open(page: Page, starts = '2026-11-02T08:00', ends = '2026-11-06T15:00'): Promise<void> {
  await registerAndEnter(page);
  await page.goto('/admin/elections/new');
  await page.getByTestId('election-starts').fill(starts);
  await page.getByTestId('election-ends').fill(ends);
}

const starts = (page: Page) => page.getByTestId('election-starts');
const ends = (page: Page) => page.getByTestId('election-ends');
const day = (page: Page, iso: string) => page.getByTestId(`picker-day-${iso}`);

test.describe('tiles', () => {
  test('show each date as a badge, the time and the date in words [NFR-UX-02]', async ({ page }) => {
    await open(page);

    await expect(page.getByTestId('election-starts-tile')).toContainText('2');
    await expect(page.getByTestId('election-starts-tile')).toContainText('08:00');
    await expect(page.getByTestId('election-starts-tile')).toContainText('lundi 2 novembre 2026');
    await expect(page.getByTestId('election-ends-tile')).toContainText('15:00');
    await expect(page.getByTestId('election-ends-tile')).toContainText('vendredi 6 novembre 2026');
    expect(await page.getByTestId('election-starts-tile').locator('svg').count()).toBeGreaterThan(0);
  });

  test('follow what is typed in the native field, and the other way round [FR-ELEC-02]', async ({ page }) => {
    await open(page);

    await ends(page).fill('2026-11-09T18:30');

    await expect(page.getByTestId('election-ends-tile')).toContainText('18:30');
    await expect(page.getByTestId('election-ends-tile')).toContainText('lundi 9 novembre 2026');
  });
});

test.describe('popover', () => {
  test('opens from a tile, shades the voting days and closes with Escape, focus back on the tile [NFR-UX-03]', async ({ page }) => {
    await open(page);
    const tile = page.getByTestId('election-starts-tile');

    await tile.click();

    const picker = page.getByTestId('date-picker');
    await expect(picker).toBeVisible();
    await expect(day(page, '2026-11-02')).toHaveAttribute('data-range', 'start');
    await expect(day(page, '2026-11-04')).toHaveAttribute('data-range', 'in');
    await expect(day(page, '2026-11-06')).toHaveAttribute('data-range', 'end');
    await expect(day(page, '2026-11-09')).not.toHaveAttribute('data-range', /start|in|end/);

    await page.keyboard.press('Escape');
    await expect(picker).toBeHidden();
    await expect(tile).toBeFocused();
  });

  test('shows two months on a desktop and one on a phone, as a bottom sheet there [NFR-UX-02]', async ({ page }) => {
    await open(page);

    await page.getByTestId('election-starts-tile').click();

    const picker = page.getByTestId('date-picker');
    await expect(page.getByTestId('picker-month-0')).toBeVisible();
    if (isPhone(page)) {
      await expect(page.getByTestId('picker-month-1')).toHaveCount(0);
      const box = (await picker.boundingBox())!;
      const viewport = page.viewportSize()!;
      expect(box.x).toBeLessThanOrEqual(1);
      expect(box.width).toBeGreaterThanOrEqual(viewport.width - 2);
      expect(box.y + box.height).toBeGreaterThanOrEqual(viewport.height - 2);
    } else {
      await expect(page.getByTestId('picker-month-1')).toBeVisible();
    }
    await expectNoSidewaysScroll(page);
  });

  test('is accessible with the new colours, open [NFR-UX-03]', async ({ page }) => {
    await open(page);

    await page.getByTestId('election-ends-tile').click();
    await expect(page.getByTestId('date-picker')).toBeVisible();

    await expectAccessible(page);
  });

  test('closes with "Valider les dates" and keeps what was picked [FR-ELEC-02]', async ({ page }) => {
    await open(page);
    await page.getByTestId('election-starts-tile').click();
    await day(page, '2026-11-03').click();

    await page.getByTestId('picker-apply').click();

    await expect(page.getByTestId('date-picker')).toBeHidden();
    await expect(starts(page)).toHaveValue('2026-11-03T08:00');
  });
});

test.describe('choosing', () => {
  test('a start day keeps the end unless the end would come first, then the duration is kept [FR-ELEC-02]', async ({ page }) => {
    await open(page, '2026-11-02T08:00', '2026-11-04T19:00');
    await page.getByTestId('election-starts-tile').click();

    await day(page, '2026-11-03').click();
    await expect(starts(page)).toHaveValue('2026-11-03T08:00');
    await expect(ends(page)).toHaveValue('2026-11-04T19:00');

    // 1 day 11 hours before; the end follows.
    await day(page, '2026-11-20').click();
    await expect(starts(page)).toHaveValue('2026-11-20T08:00');
    await expect(ends(page)).toHaveValue('2026-11-21T19:00');
  });

  test('an end day before the start moves the start back, the duration kept [FR-ELEC-02]', async ({ page }) => {
    await open(page, '2026-11-20T08:00', '2026-11-25T19:00');
    await page.getByTestId('election-ends-tile').click();

    await day(page, '2026-11-10').click();

    await expect(ends(page)).toHaveValue('2026-11-10T19:00');
    await expect(starts(page)).toHaveValue('2026-11-05T08:00');
  });

  test('time chips and steppers set the time of the field being edited [FR-ELEC-02]', async ({ page }) => {
    await open(page, '2026-11-02T08:00', '2026-11-06T19:00');
    await page.getByTestId('election-starts-tile').click();

    await page.getByTestId('picker-time-chip-12:00').click();
    await expect(starts(page)).toHaveValue('2026-11-02T12:00');

    await page.getByTestId('picker-hour-plus').click();
    await expect(starts(page)).toHaveValue('2026-11-02T13:00');
    await page.getByTestId('picker-minute-plus').click();
    await expect(starts(page)).toHaveValue('2026-11-02T13:05');
    await page.getByTestId('picker-hour-minus').click();
    await page.getByTestId('picker-minute-minus').click();
    await expect(starts(page)).toHaveValue('2026-11-02T12:00');

    await page.getByTestId('election-ends-tile').click();
    await page.getByTestId('picker-time-chip-17:00').click();
    await expect(ends(page)).toHaveValue('2026-11-06T17:00');
  });

  test('quick choices: one day, tomorrow, next Monday for five days [FR-ELEC-02]', async ({ page }) => {
    await open(page, '2026-11-02T10:30', '2026-11-06T19:00');
    await page.getByTestId('election-starts-tile').click();

    await page.getByTestId('picker-preset-one-day').click();
    await expect(starts(page)).toHaveValue('2026-11-02T08:00');
    await expect(ends(page)).toHaveValue('2026-11-02T17:00');

    const today = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Port-au-Prince' });
    const plus = (iso: string, days: number) => new Date(Date.parse(`${iso}T12:00:00Z`) + days * 86_400_000).toISOString().slice(0, 10);

    await page.getByTestId('picker-preset-tomorrow').click();
    await expect(starts(page)).toHaveValue(`${plus(today, 1)}T08:00`);
    await expect(ends(page)).toHaveValue(`${plus(today, 1)}T17:00`);

    await page.getByTestId('picker-preset-next-monday').click();
    const from = (await starts(page).inputValue()).slice(0, 10);
    const to = (await ends(page).inputValue()).slice(0, 10);
    expect(new Date(`${from}T12:00:00Z`).getUTCDay()).toBe(1);
    expect(from > today).toBe(true);
    expect(to).toBe(plus(from, 4));
    expect(await starts(page).inputValue()).toMatch(/T08:00$/);
    expect(await ends(page).inputValue()).toMatch(/T17:00$/);
  });

  test('the summary gives the duration and names the time zone [FR-ELEC-02]', async ({ page }) => {
    await open(page, '2026-11-02T08:00', '2026-11-06T15:00');

    await page.getByTestId('election-starts-tile').click();

    await expect(page.getByTestId('picker-summary')).toContainText('4 jours');
    await expect(page.getByTestId('picker-summary')).toContainText('7 heures');
    await expect(page.getByTestId('picker-summary')).toContainText('Port-au-Prince');
  });
});

test('what is picked is what is stored, in the election\'s time zone [FR-ELEC-02]', async ({ page }) => {
  await open(page, '2026-11-02T08:00', '2026-11-06T15:00');
  await page.getByTestId('election-title').fill('Choisie au calendrier');
  await page.getByTestId('election-starts-tile').click();
  await day(page, '2026-11-09').click();
  await page.getByTestId('picker-time-chip-12:00').click();
  await page.getByTestId('election-ends-tile').click();
  await day(page, '2026-11-12').click();
  await page.getByTestId('picker-apply').click();

  await page.getByTestId('election-save').click();

  await expect(page).toHaveURL(/\/admin\/elections\/[0-9a-f-]{36}$/);
  const stored = await readElection(page, page.url().split('/').pop()!);
  // 12:00 and 15:00 in Port-au-Prince in November (UTC-5).
  expect(stored.starts_at).toBe('2026-11-09T17:00:00Z');
  expect(stored.ends_at).toBe('2026-11-12T20:00:00Z');
});
