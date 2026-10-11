import { describe, expect, it } from 'vitest';
import {
  bucketLabel,
  chartShape,
  groupLines,
  labelIndexes,
  niceTicks,
  percentText,
  share,
} from './turnoutModel';

const BOX = { width: 600, height: 200, left: 40, right: 10, top: 10, bottom: 30 };

describe('share', () => {
  it('is a part of a whole between 0 and 1, and 0 for an empty whole', () => {
    expect(share(1, 4)).toBe(0.25);
    expect(share(5, 4)).toBe(1);
    expect(share(0, 4)).toBe(0);
    expect(share(3, 0)).toBe(0);
  });
});

describe('percentText', () => {
  it('keeps one decimal at most, in the language of the page', () => {
    expect(percentText(33.3, 'fr')).toBe('33,3');
    expect(percentText(33.3, 'en')).toBe('33.3');
    expect(percentText(50, 'fr')).toBe('50');
  });
});

describe('niceTicks', () => {
  it('starts at 0 and ends at or above the maximum, on round steps', () => {
    expect(niceTicks(0)).toEqual([0, 1]);
    for (const max of [1, 3, 7, 12, 99, 187, 1000, 4321]) {
      const ticks = niceTicks(max);

      expect(ticks[0]).toBe(0);
      expect(ticks[ticks.length - 1]).toBeGreaterThanOrEqual(max);
      expect(ticks.length).toBeLessThanOrEqual(8);
    }
  });

  it('uses steps of 1, 2 or 5 times a power of ten', () => {
    expect(niceTicks(187)).toEqual([0, 50, 100, 150, 200]);
    expect(niceTicks(8)).toEqual([0, 2, 4, 6, 8]);
    expect(niceTicks(1)).toEqual([0, 1]);
  });
});

describe('chartShape', () => {
  it('is empty without a point, with a baseline and ticks', () => {
    const shape = chartShape([], BOX);

    expect(shape.line).toBe('');
    expect(shape.area).toBe('');
    expect(shape.bars).toEqual([]);
    expect(shape.base).toBe(170);
    expect(shape.ticks[0]).toEqual({ value: 0, y: 170 });
  });

  it('puts the highest total at the top of the scale and the first at the lowest', () => {
    const shape = chartShape(
      [
        { at: '2026-10-12T12:00:00Z', voted: 12, total: 12 },
        { at: '2026-10-12T13:00:00Z', voted: 8, total: 20 },
      ],
      BOX,
    );

    expect(shape.dots).toHaveLength(2);
    expect(shape.dots[1]!.x).toBeGreaterThan(shape.dots[0]!.x);
    expect(shape.dots[1]!.y).toBeLessThan(shape.dots[0]!.y);
    expect(shape.ceiling).toBeGreaterThanOrEqual(20);
    expect(shape.line.startsWith('M')).toBe(true);
    expect(shape.area.endsWith('Z')).toBe(true);
    expect(shape.bars[0]!.height).toBeGreaterThan(shape.bars[1]!.height);
  });

  it('keeps one bucket inside the box', () => {
    const shape = chartShape([{ at: '2026-10-12T12:00:00Z', voted: 3, total: 3 }], BOX);

    expect(shape.dots[0]!.x).toBeGreaterThan(BOX.left);
    expect(shape.dots[0]!.x).toBeLessThan(BOX.width - BOX.right);
  });
});

describe('labelIndexes', () => {
  it('labels every bucket of a short series and spreads a long one, ends included', () => {
    expect(labelIndexes(0)).toEqual([]);
    expect(labelIndexes(3)).toEqual([0, 1, 2]);

    const many = labelIndexes(72, 5);

    expect(many).toHaveLength(5);
    expect(many[0]).toBe(0);
    expect(many[many.length - 1]).toBe(71);
  });
});

describe('bucketLabel', () => {
  it('writes the day, with the hour for an hour bucket, in the zone', () => {
    expect(bucketLabel('2026-10-12T04:00:00Z', 'day', 'en', 'America/Port-au-Prince')).toBe(
      'Oct 12',
    );
    expect(bucketLabel('2026-10-12T04:00:00Z', 'hour', 'en', 'America/Port-au-Prince')).toContain(
      'Oct 12',
    );
  });

  it('survives an unknown zone', () => {
    expect(bucketLabel('2026-10-12T04:00:00Z', 'day', 'en', 'Nope/Nowhere')).toContain('Oct');
  });
});

describe('groupLines', () => {
  it('keeps the order, names the line with no group by null and computes the share', () => {
    const lines = groupLines([
      { group: { id: 'a', name: '4e année' }, voters: 3, voted: 1 },
      { group: null, voters: 1, voted: 0 },
    ]);

    expect(lines[0]).toMatchObject({ key: 'a', name: '4e année', share: 1 / 3 });
    expect(lines[1]).toMatchObject({ key: 'none', name: null, share: 0 });
  });
});
