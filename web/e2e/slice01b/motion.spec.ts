import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/*
 * Slice 01b — the motion effects, shown on /dev/motion.
 * docs/design/frontend.md section 4, Motion (rules 1 to 7).
 */

const opacityOf = (page: Page, testId: string) =>
  page.getByTestId(testId).evaluate((element) => parseFloat(getComputedStyle(element).opacity));

/** Horizontal scale of an element's computed transform (1 when it has none). */
const scaleXOf = (page: Page, testId: string) =>
  page.getByTestId(testId).evaluate((element) => {
    const transform = getComputedStyle(element).transform;
    return transform === 'none' ? 1 : new DOMMatrixReadOnly(transform).a;
  });

test.describe('with motion', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/dev/motion');
  });

  test('reveal: appears and settles when it enters the screen [rule 5]', async ({ page }) => {
    const target = page.getByTestId('demo-reveal');

    await target.scrollIntoViewIfNeeded();

    await expect.poll(() => opacityOf(page, 'demo-reveal'), { timeout: 3000 }).toBe(1);
    await expect
      .poll(() => target.evaluate((element) => getComputedStyle(element).transform), { timeout: 3000 })
      .toMatch(/^(none|matrix\(1, 0, 0, 1, 0, 0\))$/);
  });

  test('reveal: runs once, scrolling back does not replay it [rule 5]', async ({ page }) => {
    const target = page.getByTestId('demo-reveal');

    await target.scrollIntoViewIfNeeded();
    await expect.poll(() => opacityOf(page, 'demo-reveal'), { timeout: 3000 }).toBe(1);

    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(300);
    await target.scrollIntoViewIfNeeded();

    expect(await opacityOf(page, 'demo-reveal')).toBe(1);
  });

  test('count up: ends on its value [rule 3]', async ({ page }) => {
    const target = page.getByTestId('demo-countup');

    await target.scrollIntoViewIfNeeded();

    await expect(target).toHaveText(/^87\s%$/, { timeout: 4000 });
  });

  test('grow bar: grows to its value [rule 1]', async ({ page }) => {
    await page.getByTestId('demo-growbar').scrollIntoViewIfNeeded();

    await expect.poll(() => scaleXOf(page, 'demo-growbar'), { timeout: 4000 }).toBeCloseTo(0.87, 2);
  });

  test('live dot and float: loop slowly [rule 6]', async ({ page }) => {
    const animation = (testId: string) =>
      page.getByTestId(testId).evaluate((element) => {
        const all = [element, ...Array.from(element.querySelectorAll('*'))].flatMap((node) => [
          getComputedStyle(node),
          getComputedStyle(node, '::before'),
          getComputedStyle(node, '::after'),
        ]);
        const looping = all.find((style) => style.animationIterationCount.includes('infinite'));
        return looping ? parseFloat(looping.animationDuration) : 0;
      });

    expect(await animation('demo-livedot')).toBeGreaterThanOrEqual(1.5);
    expect(await animation('demo-float')).toBeGreaterThanOrEqual(5);
  });

  test('check: the mark draws itself to the end [rule 1]', async ({ page }) => {
    const mark = page.getByTestId('demo-check');

    await mark.scrollIntoViewIfNeeded();

    await expect
      .poll(
        () =>
          mark.evaluate((element) => {
            const path = element.querySelector('path')!;
            return parseFloat(getComputedStyle(path).strokeDashoffset);
          }),
        { timeout: 4000 },
      )
      .toBe(0);
  });

  test('select: the ring appears on the chosen card [rule 7]', async ({ page }) => {
    const card = page.getByTestId('demo-select');
    const shadow = () => card.evaluate((element) => getComputedStyle(element).boxShadow);

    await card.scrollIntoViewIfNeeded();
    const before = await shadow();
    await card.click();

    await expect.poll(shadow, { timeout: 2000 }).not.toBe(before);
    expect(await shadow()).toContain('227, 232, 255'); // primary-soft
  });

  test('only movement, fading and the two exceptions are animated [rule 1]', async ({ page }) => {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.getByTestId('demo-select').click();
    await page.waitForTimeout(200);

    const properties = await page.evaluate(() => {
      const ignored = new Set(['offset', 'computedOffset', 'easing', 'composite']);
      const found = new Set<string>();
      for (const animation of document.getAnimations()) {
        const effect = animation.effect as KeyframeEffect | null;
        for (const frame of effect?.getKeyframes() ?? []) {
          for (const key of Object.keys(frame)) if (!ignored.has(key)) found.add(key);
        }
      }
      return [...found];
    });

    const allowed = ['transform', 'opacity', 'strokeDashoffset', 'backgroundPosition', 'backgroundPositionX', 'backgroundPositionY', 'boxShadow', 'borderColor', 'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor', 'backgroundColor', 'color'];
    expect(properties.filter((name) => !allowed.includes(name))).toEqual([]);
  });

  test('a control can be used before its entrance has finished [rule 3]', async ({ page }) => {
    await page.goto('/dev/motion');

    // No waiting: the button must already accept a click.
    await page.getByTestId('demo-button-accent').click({ timeout: 1500 });
  });
});

test.describe('with "reduce motion"', () => {
  test.use({ reducedMotion: 'reduce' });

  test('nothing moves and nothing is missing [rule 2]', async ({ page }) => {
    await page.goto('/dev/motion');
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(300);

    const running = await page.evaluate(
      () => document.getAnimations().filter((animation) => animation.playState === 'running').length,
    );

    expect(running).toBe(0);
    expect(await opacityOf(page, 'demo-reveal')).toBe(1);
    await expect(page.getByTestId('demo-countup')).toHaveText(/^87\s%$/);
    expect(await scaleXOf(page, 'demo-growbar')).toBeCloseTo(0.87, 2);
    expect(
      await page.getByTestId('demo-check').evaluate((element) =>
        parseFloat(getComputedStyle(element.querySelector('path')!).strokeDashoffset),
      ),
    ).toBe(0);
  });
});

test.describe('with JavaScript off', () => {
  test.use({ javaScriptEnabled: false });

  test('everything is visible [rule 3]', async ({ page }) => {
    await page.goto('/dev/motion');

    for (const testId of ['demo-hero-title', 'demo-reveal', 'demo-countup', 'demo-growbar', 'demo-check', 'demo-band']) {
      const target = page.getByTestId(testId);
      await expect(target).toBeAttached();
      expect(await target.evaluate((element) => parseFloat(getComputedStyle(element).opacity)), testId).toBe(1);
    }
    await expect(page.getByTestId('demo-countup')).toHaveText(/^87\s%$/);
  });
});

test('no animation library is installed [rule 1]', () => {
  const manifest = JSON.parse(readFileSync(fileURLToPath(new URL('../../package.json', import.meta.url)), 'utf8'));
  const names = Object.keys({ ...manifest.dependencies, ...manifest.devDependencies });
  const banned = /^(framer-motion|motion|gsap|animejs|react-spring|@react-spring\/.*|lottie-.*|@lottiefiles\/.*|aos|animate\.css|popmotion|velocity-animate)$/;

  expect(names.filter((name) => banned.test(name))).toEqual([]);
});
