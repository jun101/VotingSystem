import { describe, expect, it } from 'vitest';
import { DEFAULT_TIME_ZONE, TIME_ZONES, timeZoneChoices } from './timeZones';

describe('timeZoneChoices', () => {
  it('puts Haiti first and lists every zone once', () => {
    const choices = timeZoneChoices('America/New_York');

    expect(choices[0]).toBe(DEFAULT_TIME_ZONE);
    expect(choices).toHaveLength(TIME_ZONES.length);
    expect(new Set(choices).size).toBe(choices.length);
    expect(choices).toContain('America/New_York');
  });

  it('keeps a stored zone that is not in the list, right after Haiti', () => {
    expect(timeZoneChoices('Mars/Olympus').slice(0, 2)).toEqual([
      DEFAULT_TIME_ZONE,
      'Mars/Olympus',
    ]);
  });
});
