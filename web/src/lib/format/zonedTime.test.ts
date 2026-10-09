import { describe, expect, it } from 'vitest';
import { fromLocalInput, toLocalInput } from './zonedTime';

describe('fromLocalInput', () => {
  it('reads a local time in the zone given', () => {
    expect(fromLocalInput('2026-10-12T08:00', 'America/Port-au-Prince')?.utc.toISOString()).toBe(
      '2026-10-12T12:00:00.000Z',
    );
    expect(fromLocalInput('2026-10-12T08:00', 'Europe/Paris')?.utc.toISOString()).toBe(
      '2026-10-12T06:00:00.000Z',
    );
    expect(fromLocalInput('2026-01-12T08:00', 'Pacific/Auckland')?.utc.toISOString()).toBe(
      '2026-01-11T19:00:00.000Z',
    );
  });

  it('gives null for text that is not a full date and time or a date that does not exist', () => {
    expect(fromLocalInput('', 'UTC')).toBeNull();
    expect(fromLocalInput('2026-10-12', 'UTC')).toBeNull();
    expect(fromLocalInput('2026-02-30T08:00', 'UTC')).toBeNull();
    expect(fromLocalInput('2026-10-12T25:00', 'UTC')).toBeNull();
  });

  it('moves a time that the spring clock change skips to the nearest time that exists', () => {
    // 02:30 on 8 March 2026 does not exist in New York: the clock jumps from 02:00 to 03:00.
    const result = fromLocalInput('2026-03-08T02:30', 'America/New_York');

    expect(result?.adjusted).toBe(true);
    expect(toLocalInput(result!.utc.toISOString(), 'America/New_York')).toBe('2026-03-08T03:30');
  });

  it('reads a time that happens twice as its first occurrence', () => {
    // 01:30 on 1 November 2026 happens twice in New York (summer, then winter time).
    const result = fromLocalInput('2026-11-01T01:30', 'America/New_York');

    expect(result?.adjusted).toBe(false);
    expect(result?.utc.toISOString()).toBe('2026-11-01T05:30:00.000Z');
  });

  it('does not crash on a zone the browser does not know', () => {
    expect(fromLocalInput('2026-10-12T08:00', 'Mars/Olympus')?.utc.toISOString()).toBe(
      '2026-10-12T08:00:00.000Z',
    );
  });
});

describe('toLocalInput', () => {
  it('writes an instant as the local text of the zone', () => {
    expect(toLocalInput('2026-10-12T12:00:00Z', 'America/Port-au-Prince')).toBe('2026-10-12T08:00');
    expect(toLocalInput('2026-01-01T04:30:00Z', 'America/Port-au-Prince')).toBe('2025-12-31T23:30');
    expect(toLocalInput('2026-01-01T00:00:00Z', 'UTC')).toBe('2026-01-01T00:00');
  });

  it('gives an empty text for an instant it cannot read', () => {
    expect(toLocalInput('not a date', 'UTC')).toBe('');
  });
});
