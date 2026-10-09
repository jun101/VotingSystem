import { describe, expect, it } from 'vitest';
import { fromLocalInput, isSupportedZone, toLocalInput } from './zonedTime';

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

  it('reads a repeated time as its first occurrence east of UTC too', () => {
    // The clocks of Paris go back at 03:00 on 25 October 2026: 02:30 happens at 00:30Z and at 01:30Z.
    expect(fromLocalInput('2026-10-25T02:30', 'Europe/Paris')?.utc.toISOString()).toBe(
      '2026-10-25T00:30:00.000Z',
    );
    // Auckland goes back at 03:00 on 5 April 2026: 02:30 happens at 13:30Z (the 4th) and at 14:30Z.
    expect(fromLocalInput('2026-04-05T02:30', 'Pacific/Auckland')?.utc.toISOString()).toBe(
      '2026-04-04T13:30:00.000Z',
    );
  });

  it('keeps the times next to a clock change as they are', () => {
    expect(fromLocalInput('2026-10-25T01:59', 'Europe/Paris')?.utc.toISOString()).toBe(
      '2026-10-24T23:59:00.000Z',
    );
    expect(fromLocalInput('2026-10-25T03:00', 'Europe/Paris')?.utc.toISOString()).toBe(
      '2026-10-25T02:00:00.000Z',
    );
  });

  it('moves a skipped time east of UTC to the nearest one that exists', () => {
    // 02:30 on 29 March 2026 does not exist in Paris (02:00 jumps to 03:00).
    const result = fromLocalInput('2026-03-29T02:30', 'Europe/Paris');

    expect(result?.adjusted).toBe(true);
    expect(toLocalInput(result!.utc.toISOString(), 'Europe/Paris')).toBe('2026-03-29T03:30');
  });

  it('converts nothing in a zone the browser does not know', () => {
    expect(fromLocalInput('2026-10-12T08:00', 'Mars/Olympus')).toBeNull();
    expect(isSupportedZone('Mars/Olympus')).toBe(false);
    expect(isSupportedZone('Europe/Paris')).toBe(true);
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
    expect(toLocalInput('2026-10-12T12:00:00Z', 'Mars/Olympus')).toBe('');
  });
});
