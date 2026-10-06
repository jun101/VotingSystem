import { expect, test } from '@playwright/test';
import { rgb } from '../support/checks';

/*
 * Slice 01b — the tokens of the showcase theme and of motion.
 * docs/design/frontend.md section 4.
 */

test('exposes the showcase colours as CSS variables [NFR-UX-02]', async ({ page }) => {
  await page.goto('/dev/motion');

  // Read as the browser resolves it, so "#fff" and "#FFFFFF" are the same.
  const colour = (name: string) =>
    page.evaluate((n) => {
      if (getComputedStyle(document.documentElement).getPropertyValue(n).trim() === '') return '';
      const probe = document.createElement('span');
      probe.style.color = `var(${n})`;
      document.body.appendChild(probe);
      const resolved = getComputedStyle(probe).color;
      probe.remove();
      return resolved;
    }, name);

  expect(await colour('--color-navy-deep')).toBe(rgb('#061A3D'));
  expect(await colour('--color-hero-from')).toBe(rgb('#09295B'));
  expect(await colour('--color-hero-mid')).toBe(rgb('#0D3A7A'));
  expect(await colour('--color-hero-to')).toBe(rgb('#1A4A8A'));
  expect(await colour('--color-accent')).toBe(rgb('#FF8603'));
  expect(await colour('--color-accent-light')).toBe(rgb('#FFB45A'));
  // The tokens of slice 01 are unchanged.
  expect(await colour('--color-primary')).toBe(rgb('#1E3A8A'));
  expect(await colour('--color-warm')).toBe(rgb('#C2410C'));
});

test('exposes the durations and easings of motion [NFR-UX-02]', async ({ page }) => {
  await page.goto('/dev/motion');

  const value = (name: string) =>
    page.evaluate((n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(), name);
  const ms = (text: string) => (text.endsWith('ms') ? parseFloat(text) : parseFloat(text) * 1000);

  expect(ms(await value('--duration-fast'))).toBe(150);
  expect(ms(await value('--duration-base'))).toBe(250);
  expect(ms(await value('--duration-reveal'))).toBe(750);
  expect(await value('--ease-out-soft')).toMatch(/^cubic-bezier\(/);
  expect(await value('--ease-spring')).not.toBe('');
});
