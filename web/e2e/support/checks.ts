import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

/*
 * Checks shared by the browser acceptance tests. Part of the acceptance harness.
 */

/** WCAG 2.1 A and AA, automated part (NFR-UX-03). */
export async function expectAccessible(page: Page): Promise<void> {
  // Let every entrance animation finish: a half-faded text has a lower contrast than the one a
  // person sees a moment later. Animations that loop on purpose are left running.
  await page.evaluate(() =>
    Promise.all(
      document
        .getAnimations()
        .filter((animation) => animation.effect?.getComputedTiming().iterations !== Infinity)
        .map((animation) => animation.finished.catch(() => undefined)),
    ),
  );

  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();

  expect(
    results.violations.map((v) => `${v.id}: ${v.help} (${v.nodes.length})`),
    'accessibility violations',
  ).toEqual([]);
}

/** The page never scrolls sideways (NFR-UX-02). */
export async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );

  expect(overflow, 'pixels wider than the screen').toBeLessThanOrEqual(0);
}

/**
 * Records every request the page makes. Call before page.goto().
 * Fonts, scripts and styles must all come from our own origin: no font service, no CDN.
 */
export function recordForeignRequests(page: Page, baseURL: string): string[] {
  const own = new URL(baseURL).origin;
  const foreign: string[] = [];

  page.on('request', (request) => {
    const url = request.url();
    if (url.startsWith('data:') || url.startsWith('blob:')) return;
    if (new URL(url).origin !== own) foreign.push(url);
  });

  return foreign;
}

/** "rgb(84, 104, 212)" for "#5468D4". */
export function rgb(hex: string): string {
  const n = parseInt(hex.replace('#', ''), 16);
  return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`;
}

export async function cssOf(page: Page, testId: string, property: string): Promise<string> {
  return page.getByTestId(testId).evaluate(
    (element, name) => getComputedStyle(element).getPropertyValue(name),
    property,
  );
}

export const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
